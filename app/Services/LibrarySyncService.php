<?php

namespace App\Services;

use App\Models\Integration;
use App\Models\MediaItem;
use App\Models\User;
use App\Models\WishlistItem;
use App\Models\WishlistRequester;
use Illuminate\Http\Client\RequestException as HttpRequestException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

class LibrarySyncService
{
    private const RESERVED_BYTES = 20 * 1024 * 1024 * 1024;

    public function __construct(private SourceIntegrationProvider $sourceIntegrationProvider) {}

    /**
     * Verify the configured integration credentials.
     */
    public function test(Integration $integration): void
    {
        $this->sourceIntegrationProvider->testConnection($integration);
    }

    /**
     * Import all items for one integration.
     */
    public function sync(Integration $integration): bool
    {
        try {
            $items = $this->sourceIntegrationProvider->media($integration);

            $externalIds = [];

            foreach ($items as $item) {
                if (! is_array($item) || ! isset($item['id'])) {
                    continue;
                }

                $externalId = (string) $item['id'];
                $externalIds[] = $externalId;

                MediaItem::query()->updateOrCreate(
                    ['source' => $integration->source, 'external_id' => $externalId],
                    $this->mapItem($integration->source, $item),
                );
            }

            $staleItems = MediaItem::query()->where('source', $integration->source);

            if ($externalIds !== []) {
                $staleItems->whereNotIn('external_id', $externalIds);
            }

            $staleItems->delete();

            $integration->forceFill([
                'last_synced_at' => now(),
                'last_error' => null,
            ])->save();

            return true;
        } catch (Throwable $exception) {
            $integration->forceFill(['last_error' => Str::limit($exception->getMessage(), 1000)])->save();

            report($exception);

            return false;
        }
    }

    /**
     * Remove an item and its files from the source service, then from the local index.
     */
    public function delete(MediaItem $item): void
    {
        $integration = Integration::query()->where('source', $item->source)->first();

        if ($integration === null) {
            throw new \RuntimeException('The source integration is no longer configured.');
        }

        $this->sourceIntegrationProvider->deleteMedia($integration, $item->external_id);

        $item->delete();
    }

    /**
     * Search a source service for titles that can be added to the wishlist.
     *
     * @return array<int, array{source: string, external_id: string, type: string, title: string, poster_url: string|null, imdb_rating: float|null, overview: string|null, payload: array<string, mixed>}>
     */
    public function lookup(Integration $integration, string $term): array
    {
        $results = $this->sourceIntegrationProvider->lookup($integration, $term);

        return collect($results)
            ->filter(fn (mixed $result): bool => is_array($result))
            ->map(fn (array $result): ?array => $this->mapWishlistLookup($integration->source, $result))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Get the quality profiles available from a source service.
     *
     * @return array<int, array{id: int, name: string}>
     */
    public function qualityProfiles(Integration $integration): array
    {
        $profiles = $this->sourceIntegrationProvider->qualityProfiles($integration);

        return collect($profiles)
            ->filter(fn (mixed $profile): bool => is_array($profile) && isset($profile['id'], $profile['name']))
            ->map(fn (array $profile): array => ['id' => (int) $profile['id'], 'name' => (string) $profile['name']])
            ->values()
            ->all();
    }

    /**
     * Add a member to a shared wishlist item, creating it when necessary.
     *
     * @param  array{source: string, external_id: string, type: string, title: string, poster_url: string|null, imdb_rating: float|null, overview: string|null, payload: array<string, mixed>}  $lookupResult
     */
    public function addWishlistItem(User $user, array $lookupResult): WishlistItem
    {
        $item = WishlistItem::query()->firstOrCreate(
            ['source' => $lookupResult['source'], 'external_id' => $lookupResult['external_id']],
            [
                'type' => $lookupResult['type'],
                'title' => $lookupResult['title'],
                'poster_url' => $lookupResult['poster_url'],
                'status' => WishlistItem::PENDING,
                'source_payload' => $lookupResult['payload'],
            ],
        );

        WishlistRequester::query()->firstOrCreate([
            'wishlist_item_id' => $item->id,
            'user_id' => $user->id,
        ]);

        return $item;
    }

    /**
     * Remove a member's wish, deleting an unrequested orphaned queue item.
     */
    public function cancelWishlistItem(User $user, WishlistItem $item): void
    {
        WishlistRequester::query()
            ->whereBelongsTo($item)
            ->whereBelongsTo($user)
            ->delete();

        if ($item->status === WishlistItem::PENDING && ! $item->requesters()->exists()) {
            $item->delete();
        }
    }

    /**
     * Process pending titles in FIFO order for each connected source.
     *
     * @return array{requested: int, skipped: int}
     */
    public function processWishlist(): array
    {
        $lock = Cache::lock('wishlist-processing', 3600);

        if (! $lock->get()) {
            return ['requested' => 0, 'skipped' => 0];
        }

        try {
            $result = ['requested' => 0, 'skipped' => 0];

            Integration::query()
                ->whereIn('source', [Integration::RADARR, Integration::SONARR])
                ->each(function (Integration $integration) use (&$result): void {
                    WishlistItem::query()
                        ->where('source', $integration->source)
                        ->where('status', WishlistItem::PENDING)
                        ->oldest()
                        ->each(function (WishlistItem $item) use ($integration, &$result): void {
                            if ($this->processWishlistItem($integration, $item)) {
                                $result['requested']++;

                                return;
                            }

                            $result['skipped']++;
                        });
                });

            return $result;
        } finally {
            $lock->release();
        }
    }

    /**
     * Request one title when the source's most spacious root has enough room.
     */
    private function processWishlistItem(Integration $integration, WishlistItem $item): bool
    {
        try {
            $rootFolder = $this->bestRootFolder($integration);

            if ($rootFolder === null) {
                $this->recordWishlistReason($item, 'No usable root folder was reported by the source service.');

                return false;
            }

            $item->forceFill(['selected_root_folder' => $rootFolder['path']]);

            if ($rootFolder['free_space'] < self::RESERVED_BYTES) {
                $this->recordWishlistReason($item, 'Not enough free space after preserving the 20 GB reserve.');

                return false;
            }

            $sourceItemId = $item->source_item_id;

            if ($sourceItemId === null) {
                $response = $this->sourceIntegrationProvider->addMedia(
                    $integration,
                    $this->sourcePayload($integration, $item, $rootFolder['path']),
                );

                if (! isset($response['id'])) {
                    throw new \UnexpectedValueException('The source service did not return the added title ID.');
                }

                $sourceItemId = (string) $response['id'];
                $item->forceFill(['source_item_id' => $sourceItemId])->save();
            }

            $this->sourceIntegrationProvider->searchMedia($integration, (int) $sourceItemId);

            $item->forceFill([
                'status' => WishlistItem::REQUESTED,
                'requested_at' => now(),
                'selected_root_folder' => $rootFolder['path'],
            ])->save();
            $item->logs()->create([
                'event' => 'wishlist.requested',
                'message' => 'The title was added to the source service and queued for search.',
                'context' => ['source_item_id' => $sourceItemId, 'root_folder' => $rootFolder['path']],
            ]);

            return true;
        } catch (Throwable $exception) {
            $this->recordWishlistReason($item, $this->wishlistExceptionMessage($exception), $this->wishlistExceptionContext($exception));
            report($exception);

            return false;
        }
    }

    /**
     * @return array{path: string, free_space: int}|null
     */
    private function bestRootFolder(Integration $integration): ?array
    {
        $rootFolders = $this->sourceIntegrationProvider->rootFolders($integration);
        $diskSpaces = $this->sourceIntegrationProvider->diskSpaces($integration);

        $diskSpacesByPath = collect($diskSpaces)
            ->filter(fn (mixed $diskSpace): bool => is_array($diskSpace) && isset($diskSpace['path'], $diskSpace['freeSpace']))
            ->map(fn (array $diskSpace): array => ['path' => (string) $diskSpace['path'], 'free_space' => (int) $diskSpace['freeSpace']]);

        return collect($rootFolders)
            ->filter(fn (mixed $rootFolder): bool => is_array($rootFolder) && isset($rootFolder['path']) && ($rootFolder['accessible'] ?? true))
            ->map(function (array $rootFolder) use ($diskSpacesByPath): ?array {
                $rootFolderPath = (string) $rootFolder['path'];
                $freeSpace = isset($rootFolder['freeSpace']) ? (int) $rootFolder['freeSpace'] : $diskSpacesByPath
                    ->filter(fn (array $diskSpace): bool => Str::startsWith($rootFolderPath, $diskSpace['path']))
                    ->sortByDesc(fn (array $diskSpace): int => Str::length($diskSpace['path']))
                    ->value('free_space');

                return $freeSpace === null ? null : ['path' => $rootFolderPath, 'free_space' => $freeSpace];
            })
            ->filter()
            ->sortByDesc('free_space')
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function sourcePayload(Integration $integration, WishlistItem $item, string $rootFolder): array
    {
        $payload = $item->source_payload;
        unset($payload['id'], $payload['path'], $payload['folder'], $payload['statistics'], $payload['hasFile']);

        $payload['rootFolderPath'] = $rootFolder;
        $payload['monitored'] = true;
        $payload['qualityProfileId'] = $integration->default_quality_profile_id ?? ($payload['qualityProfileId'] ?? null);
        $payload['addOptions'] = $integration->source === Integration::RADARR
            ? ['searchForMovie' => false]
            : ['searchForMissingEpisodes' => false];

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function wishlistExceptionContext(Throwable $exception): array
    {
        if (! $exception instanceof HttpRequestException) {
            return [];
        }

        return [
            'status' => $exception->response->status(),
            'source_response' => $exception->response->body(),
        ];
    }

    private function wishlistExceptionMessage(Throwable $exception): string
    {
        if (! $exception instanceof HttpRequestException) {
            return $exception->getMessage();
        }

        return "HTTP request returned status code {$exception->response->status()}:\n{$exception->response->body()}";
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function recordWishlistReason(WishlistItem $item, string $reason, array $context = []): void
    {
        $item->forceFill([
            'status' => WishlistItem::PENDING,
        ])->save();
        $item->logs()->create([
            'event' => 'wishlist.deferred',
            'message' => $reason,
            'context' => $context,
        ]);
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array{source: string, external_id: string, type: string, title: string, poster_url: string|null, imdb_rating: float|null, overview: string|null, payload: array<string, mixed>}|null
     */
    private function mapWishlistLookup(string $source, array $result): ?array
    {
        $externalId = $source === Integration::RADARR
            ? $result['tmdbId'] ?? $result['imdbId'] ?? null
            : $result['tvdbId'] ?? null;

        if ($externalId === null || ! isset($result['title'])) {
            return null;
        }

        $images = Arr::wrap($result['images'] ?? []);
        $poster = Arr::first($images, fn (mixed $image): bool => is_array($image) && Arr::get($image, 'coverType') === 'poster');
        $imdbRating = Arr::get($result, 'ratings.imdb.value');

        return [
            'source' => $source,
            'external_id' => (string) $externalId,
            'type' => $source === Integration::RADARR ? 'movie' : 'series',
            'title' => (string) $result['title'],
            'poster_url' => is_array($poster) ? ($poster['remoteUrl'] ?? $poster['url'] ?? null) : null,
            'imdb_rating' => is_numeric($imdbRating) ? (float) $imdbRating : null,
            'overview' => filled($result['overview'] ?? null) ? trim((string) $result['overview']) : null,
            'payload' => $result,
        ];
    }

    /**
     * Convert a source payload to the unified catalog shape.
     *
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function mapItem(string $source, array $item): array
    {
        $images = Arr::wrap($item['images'] ?? []);
        $poster = Arr::first($images, fn (mixed $image): bool => is_array($image) && Arr::get($image, 'coverType') === 'poster');

        return [
            'type' => $source === Integration::RADARR ? 'movie' : 'series',
            'title' => (string) ($item['title'] ?? 'Untitled'),
            'sort_title' => (string) ($item['sortTitle'] ?? $item['title'] ?? 'Untitled'),
            'year' => isset($item['year']) ? (int) $item['year'] : null,
            'overview' => $item['overview'] ?? null,
            'poster_url' => is_array($poster) ? ($poster['remoteUrl'] ?? $poster['url'] ?? null) : null,
            'is_monitored' => (bool) ($item['monitored'] ?? false),
            'is_available' => $source === Integration::RADARR
                ? (bool) ($item['hasFile'] ?? false)
                : (int) Arr::get($item, 'statistics.episodeFileCount', 0) > 0,
            'source_metadata' => $item,
        ];
    }
}
