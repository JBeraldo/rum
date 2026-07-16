<?php

namespace App\Services;

use App\Models\DownloadClient;
use App\Models\DownloadTransfer;
use App\Models\Integration;
use App\Models\MediaItem;
use App\Models\WishlistItem;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\Client\RequestException as HttpRequestException;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;
use Throwable;

class DownloadSyncService
{
    private const UNKNOWN_ETA_SECONDS = 8_640_000;

    public function __construct(
        private QbittorrentProvider $qbittorrentProvider,
        private SourceIntegrationProvider $sourceIntegrationProvider,
    ) {}

    /**
     * Verify that qBittorrent authentication and Web API access work.
     */
    public function test(DownloadClient $client): string
    {
        return $this->qbittorrentProvider->webApiVersion($client);
    }

    /**
     * Synchronize media-linked qBittorrent transfers.
     *
     * @return array{successful: bool, tracked: int, active: int, removed: int}
     */
    public function sync(DownloadClient $client): array
    {
        try {
            $queueMap = $this->sourceQueueMap();
            $torrents = $this->qbittorrentProvider->torrents($client);

            $result = $this->synchronizeTransfers($client, $queueMap, $torrents);

            $client->forceFill([
                'last_synced_at' => now(),
                'last_error' => null,
            ])->save();

            return ['successful' => true, ...$result];
        } catch (Throwable $exception) {
            $message = $this->exceptionMessage($exception);
            $previousError = $client->last_error;

            $client->forceFill(['last_error' => $message])->save();

            if ($previousError !== $message) {
                $client->logs()->create([
                    'event' => 'download.sync_failed',
                    'message' => $message,
                    'context' => $this->exceptionContext($exception),
                ]);
            }

            report($exception);

            return ['successful' => false, 'tracked' => 0, 'active' => 0, 'removed' => 0];
        }
    }

    public function exceptionMessage(Throwable $exception): string
    {
        if (! $exception instanceof HttpRequestException) {
            return $exception->getMessage();
        }

        return "HTTP request returned status code {$exception->response->status()}:\n{$exception->response->body()}";
    }

    /**
     * @param  array<string, array{source: string, source_item_id: string}>  $queueMap
     * @param  array<int, mixed>  $torrents
     * @return array{tracked: int, active: int, removed: int}
     */
    private function synchronizeTransfers(DownloadClient $client, array $queueMap, array $torrents): array
    {
        $syncStartedAt = now();
        $torrentsByHash = collect($torrents)
            ->filter(fn (mixed $torrent): bool => is_array($torrent) && filled($torrent['hash'] ?? null))
            ->mapWithKeys(function (array $torrent): array {
                $hash = Str::lower(trim((string) $torrent['hash']));

                return [$hash => $torrent];
            });

        $existingTransfers = $client->transfers()
            ->whereIn('torrent_hash', $torrentsByHash->keys())
            ->with(['mediaItem', 'wishlistItem'])
            ->get()
            ->keyBy('torrent_hash');

        $matches = collect($queueMap);

        foreach ($existingTransfers as $existingTransfer) {
            $matches->put($existingTransfer->torrent_hash, [
                'source' => $existingTransfer->source,
                'source_item_id' => $existingTransfer->source_item_id,
            ]);
        }

        [$mediaItems, $wishlistItems] = $this->matchedModels($matches);
        $seenHashes = [];

        foreach ($torrentsByHash as $hash => $torrent) {
            $match = $queueMap[$hash] ?? $matches->get($hash);

            if ($match === null) {
                continue;
            }

            $seenHashes[] = $hash;
            $modelKey = $this->sourceItemKey($match['source'], $match['source_item_id']);
            $mediaItem = $mediaItems->get($modelKey);
            $wishlistItem = $wishlistItems->get($modelKey);
            $existingTransfer = $existingTransfers->get($hash);
            $wasComplete = $existingTransfer !== null && (float) $existingTransfer->progress >= 1;
            $previousState = $existingTransfer?->state;
            $transfer = $existingTransfer ?? new DownloadTransfer([
                'download_client_id' => $client->id,
                'torrent_hash' => $hash,
            ]);

            $progress = min(1, max(0, (float) ($torrent['progress'] ?? 0)));
            $completedAt = $this->timestamp($torrent['completion_on'] ?? null)
                ?? ($progress >= 1 ? $transfer->completed_at ?? now() : null);

            $transfer->fill([
                'media_item_id' => $mediaItem?->id,
                'wishlist_item_id' => $wishlistItem?->id,
                'source' => $match['source'],
                'source_item_id' => $match['source_item_id'],
                'name' => (string) ($torrent['name'] ?? 'Untitled download'),
                'progress' => $progress,
                'state' => (string) ($torrent['state'] ?? 'unknown'),
                'download_speed' => max(0, (int) ($torrent['dlspeed'] ?? 0)),
                'eta_seconds' => $this->eta($torrent['eta'] ?? null),
                'size_bytes' => max(0, (int) ($torrent['size'] ?? 0)),
                'amount_left_bytes' => max(0, (int) ($torrent['amount_left'] ?? 0)),
                'category' => filled($torrent['category'] ?? null) ? (string) $torrent['category'] : null,
                'content_path' => filled($torrent['content_path'] ?? null) ? (string) $torrent['content_path'] : null,
                'added_at' => $this->timestamp($torrent['added_on'] ?? null),
                'completed_at' => $completedAt,
                'last_seen_at' => $syncStartedAt,
                'removed_at' => null,
            ]);
            $transfer->save();
            $transfer->setRelation('mediaItem', $mediaItem);
            $transfer->setRelation('wishlistItem', $wishlistItem);

            if ($existingTransfer === null) {
                $this->recordTransferEvent(
                    $transfer,
                    $progress >= 1 ? 'download.completed' : 'download.started',
                    $progress >= 1 ? 'The download completed.' : 'The download started.',
                );
            } elseif (! $wasComplete && $progress >= 1) {
                $this->recordTransferEvent($transfer, 'download.completed', 'The download completed.');
            }

            if ($this->isErrorState($transfer->state) && ! $this->isErrorState($previousState)) {
                $this->recordTransferEvent($transfer, 'download.failed', 'qBittorrent reported a download error.');
            }
        }

        $removed = $this->markMissingTransfersRemoved($client, $seenHashes, $syncStartedAt);

        return [
            'tracked' => count($seenHashes),
            'active' => $client->transfers()->active()->count(),
            'removed' => $removed,
        ];
    }

    /**
     * Build a case-insensitive torrent-hash map from configured Radarr and Sonarr queues.
     *
     * @return array<string, array{source: string, source_item_id: string}>
     */
    private function sourceQueueMap(): array
    {
        $queueMap = [];

        Integration::query()
            ->whereIn('source', [Integration::RADARR, Integration::SONARR])
            ->get()
            ->each(function (Integration $integration) use (&$queueMap): void {
                foreach ($this->queueRecords($integration) as $record) {
                    $downloadId = $record['downloadId'] ?? null;
                    $sourceItemId = $integration->source === Integration::RADARR
                        ? $record['movieId'] ?? Arr::get($record, 'movie.id')
                        : $record['seriesId'] ?? Arr::get($record, 'series.id');

                    if (! filled($downloadId) || ! filled($sourceItemId)) {
                        continue;
                    }

                    $queueMap[Str::lower(trim((string) $downloadId))] = [
                        'source' => $integration->source,
                        'source_item_id' => (string) $sourceItemId,
                    ];
                }
            });

        return $queueMap;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function queueRecords(Integration $integration): array
    {
        $payload = $this->sourceIntegrationProvider->queue($integration);

        $records = array_is_list($payload) ? $payload : ($payload['records'] ?? []);

        if (! is_array($records)) {
            throw new \UnexpectedValueException(ucfirst($integration->source).' returned an invalid queue record list.');
        }

        return collect($records)->filter(fn (mixed $record): bool => is_array($record))->values()->all();
    }

    /**
     * Load the media and wishlist records associated with the source queue in two queries.
     *
     * @param  Collection<string, array{source: string, source_item_id: string}>  $matches
     * @return array{EloquentCollection<string, MediaItem>, EloquentCollection<string, WishlistItem>}
     */
    private function matchedModels(Collection $matches): array
    {
        $idsBySource = $matches
            ->groupBy('source')
            ->map(fn (Collection $sourceMatches): array => $sourceMatches->pluck('source_item_id')->unique()->values()->all());

        if ($idsBySource->isEmpty()) {
            return [new EloquentCollection, new EloquentCollection];
        }

        $mediaItems = MediaItem::query()
            ->where(function (Builder $query) use ($idsBySource): void {
                foreach ($idsBySource as $source => $sourceItemIds) {
                    $query->orWhere(fn (Builder $sourceQuery) => $sourceQuery
                        ->where('source', $source)
                        ->whereIn('external_id', $sourceItemIds));
                }
            })
            ->get()
            ->keyBy(fn (MediaItem $item): string => $this->sourceItemKey($item->source, $item->external_id));

        $wishlistItems = WishlistItem::query()
            ->where(function (Builder $query) use ($idsBySource): void {
                foreach ($idsBySource as $source => $sourceItemIds) {
                    $query->orWhere(fn (Builder $sourceQuery) => $sourceQuery
                        ->where('source', $source)
                        ->whereIn('source_item_id', $sourceItemIds));
                }
            })
            ->get()
            ->keyBy(fn (WishlistItem $item): string => $this->sourceItemKey($item->source, (string) $item->source_item_id));

        return [$mediaItems, $wishlistItems];
    }

    /**
     * @param  array<int, string>  $seenHashes
     */
    private function markMissingTransfersRemoved(DownloadClient $client, array $seenHashes, CarbonInterface $removedAt): int
    {
        $query = $client->transfers()->whereNull('removed_at')->with(['mediaItem', 'wishlistItem']);

        if ($seenHashes !== []) {
            $query->whereNotIn('torrent_hash', $seenHashes);
        }

        $removed = 0;

        $query->each(function (DownloadTransfer $transfer) use ($removedAt, &$removed): void {
            $transfer->forceFill([
                'state' => 'removed',
                'download_speed' => 0,
                'removed_at' => $removedAt,
            ])->save();
            $this->recordTransferEvent($transfer, 'download.removed', 'The download was removed from qBittorrent.');
            $removed++;
        });

        return $removed;
    }

    private function recordTransferEvent(DownloadTransfer $transfer, string $event, string $message): void
    {
        $subject = $transfer->mediaItem ?? $transfer->wishlistItem;

        if (! $subject instanceof MediaItem && ! $subject instanceof WishlistItem) {
            return;
        }

        $subject->logs()->create([
            'event' => $event,
            'message' => $message,
            'context' => [
                'torrent_hash' => $transfer->torrent_hash,
                'state' => $transfer->state,
                'progress' => $transfer->progressPercentage(),
            ],
        ]);
    }

    private function sourceItemKey(string $source, string $sourceItemId): string
    {
        return $source.':'.$sourceItemId;
    }

    private function eta(mixed $eta): ?int
    {
        if (! is_numeric($eta)) {
            return null;
        }

        $seconds = max(0, (int) $eta);

        return $seconds >= self::UNKNOWN_ETA_SECONDS ? null : $seconds;
    }

    private function timestamp(mixed $timestamp): ?CarbonInterface
    {
        if (! is_numeric($timestamp) || (int) $timestamp <= 0) {
            return null;
        }

        return Date::createFromTimestamp((int) $timestamp);
    }

    private function isErrorState(?string $state): bool
    {
        return in_array($state, ['error', 'missingFiles', 'unknown'], true);
    }

    /**
     * @return array<string, mixed>
     */
    private function exceptionContext(Throwable $exception): array
    {
        if (! $exception instanceof HttpRequestException) {
            return [];
        }

        return [
            'status' => $exception->response->status(),
            'source_response' => $exception->response->body(),
        ];
    }
}
