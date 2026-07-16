<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Integration;
use App\Models\MediaItem;
use App\Models\Role;
use App\Models\User;
use App\Models\WishlistItem;
use App\Services\LibrarySyncService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class WishlistTest extends TestCase
{
    use RefreshDatabase;

    public function test_members_can_share_a_wish_and_only_cancel_their_own_request(): void
    {
        $firstMember = User::factory()->create();
        $secondMember = User::factory()->create();
        $service = app(LibrarySyncService::class);
        $lookupResult = $this->lookupResult();

        $item = $service->addWishlistItem($firstMember, $lookupResult);
        $sharedItem = $service->addWishlistItem($secondMember, $lookupResult);

        $this->assertTrue($item->is($sharedItem));
        $this->assertSame(2, $item->requesters()->count());

        $service->cancelWishlistItem($firstMember, $item);

        $this->assertModelExists($item);
        $this->assertSame(1, $item->requesters()->count());
        $this->assertSame($secondMember->id, $item->requesters()->value('user_id'));

        $service->cancelWishlistItem($secondMember, $item);

        $this->assertModelMissing($item);
    }

    public function test_only_administrators_can_manually_process_the_wishlist(): void
    {
        Livewire::actingAs(User::factory()->create())
            ->test('pages::wishlist')
            ->call('process')
            ->assertForbidden();

        Livewire::actingAs($this->admin())
            ->test('pages::wishlist')
            ->assertSee('Process wishlist now');
    }

    public function test_the_wishlist_page_only_shows_the_shared_queue(): void
    {
        Livewire::actingAs(User::factory()->create())
            ->test('pages::wishlist')
            ->assertDontSee('Search titles');
    }

    public function test_members_can_add_titles_from_the_wishlist_modal(): void
    {
        $member = User::factory()->create();
        Integration::query()->create($this->integrationAttributes(Integration::RADARR));

        Http::preventStrayRequests();
        Http::fake([
            'http://radarr.test/api/v3/movie/lookup*' => Http::response([$this->lookupResult()['payload']]),
        ]);

        Livewire::actingAs($member)
            ->test('wishlist-add-modal')
            ->set('search', 'A movie')
            ->call('searchTitles')
            ->assertSee('IMDb 8.6')
            ->assertSee('A synopsis for the wishlist result.')
            ->call('addResult', 0);

        $this->assertDatabaseHas('wishlist_items', [
            'source' => Integration::RADARR,
            'external_id' => '321',
            'title' => 'A movie',
        ]);
        $this->assertDatabaseHas('wishlist_requesters', ['user_id' => $member->id]);
    }

    public function test_radarr_requests_the_oldest_item_using_the_root_with_most_free_space(): void
    {
        Integration::query()->create($this->integrationAttributes(Integration::RADARR, 'http://radarr.test', 3));
        $item = WishlistItem::query()->create($this->wishlistAttributes());

        Http::preventStrayRequests();
        Http::fake([
            'http://radarr.test/api/v3/rootfolder' => Http::response([
                ['path' => '/media/small', 'freeSpace' => 40 * 1024 * 1024 * 1024],
                ['path' => '/media/large', 'freeSpace' => 50 * 1024 * 1024 * 1024],
            ]),
            'http://radarr.test/api/v3/diskspace' => Http::response([
                ['path' => '/', 'freeSpace' => 100 * 1024 * 1024 * 1024],
            ]),
            'http://radarr.test/api/v3/movie' => Http::response(['id' => 91]),
            'http://radarr.test/api/v3/command' => Http::response([], 201),
        ]);

        $result = app(LibrarySyncService::class)->processWishlist();

        $this->assertSame(['requested' => 1, 'skipped' => 0], $result);
        $item->refresh();
        $this->assertSame(WishlistItem::REQUESTED, $item->status);
        $this->assertSame('/media/large', $item->selected_root_folder);
        $this->assertNotNull($item->requested_at);
        Http::assertSent(fn ($request): bool => $request->url() === 'http://radarr.test/api/v3/movie'
            && $request['rootFolderPath'] === '/media/large'
            && $request['monitored'] === true
            && $request['qualityProfileId'] === 3);
        Http::assertSent(fn ($request): bool => $request->url() === 'http://radarr.test/api/v3/command'
            && $request['name'] === 'MoviesSearch'
            && $request['movieIds'] === [91]);
    }

    public function test_insufficient_space_leaves_an_item_pending_without_requesting_the_source(): void
    {
        Integration::query()->create($this->integrationAttributes(Integration::RADARR));
        $item = WishlistItem::query()->create($this->wishlistAttributes());

        Http::preventStrayRequests();
        Http::fake([
            'http://radarr.test/api/v3/rootfolder' => Http::response([
                ['path' => '/media', 'freeSpace' => 19 * 1024 * 1024 * 1024],
            ]),
            'http://radarr.test/api/v3/diskspace' => Http::response([
                ['path' => '/', 'freeSpace' => 100 * 1024 * 1024 * 1024],
            ]),
        ]);

        $result = app(LibrarySyncService::class)->processWishlist();

        $this->assertSame(['requested' => 0, 'skipped' => 1], $result);
        $item->refresh();
        $this->assertSame(WishlistItem::PENDING, $item->status);
        $log = $item->logs()->latest()->firstOrFail();
        $this->assertSame('wishlist.deferred', $log->event);
        $this->assertNotNull($log->message);
        $this->assertTrue($item->latestError()->firstOrFail()->is($log));
        Http::assertNotSent(fn ($request): bool => $request->method() === 'POST');
    }

    public function test_sonarr_requests_a_series_and_source_errors_remain_retryable(): void
    {
        Integration::query()->create($this->integrationAttributes(Integration::SONARR, 'http://sonarr.test'));
        $item = WishlistItem::query()->create($this->wishlistAttributes([
            'source' => Integration::SONARR,
            'external_id' => '801',
            'type' => 'series',
            'source_payload' => ['title' => 'A series', 'tvdbId' => 801, 'qualityProfileId' => 1],
        ]));

        Http::preventStrayRequests();
        Http::fake([
            'http://sonarr.test/api/v3/rootfolder' => Http::response([
                ['path' => '/tv', 'freeSpace' => 100 * 1024 * 1024 * 1024],
            ]),
            'http://sonarr.test/api/v3/diskspace' => Http::sequence()
                ->push([], 500)
                ->push([], 500)
                ->push([], 500)
                ->push([['path' => '/tv', 'freeSpace' => 100 * 1024 * 1024 * 1024]]),
            'http://sonarr.test/api/v3/series' => Http::response(['id' => 73]),
            'http://sonarr.test/api/v3/command' => Http::response([], 201),
        ]);

        app(LibrarySyncService::class)->processWishlist();
        $this->assertSame(WishlistItem::PENDING, $item->refresh()->status);
        $this->assertSame('wishlist.deferred', $item->logs()->latest()->value('event'));

        app(LibrarySyncService::class)->processWishlist();
        $this->assertSame(WishlistItem::REQUESTED, $item->refresh()->status);
        Http::assertSent(fn ($request): bool => $request->url() === 'http://sonarr.test/api/v3/command'
            && $request['name'] === 'SeriesSearch'
            && $request['seriesId'] === 73);
    }

    public function test_source_errors_log_the_complete_response_body(): void
    {
        Integration::query()->create($this->integrationAttributes(Integration::RADARR));
        $item = WishlistItem::query()->create($this->wishlistAttributes());
        $sourceError = str_repeat('quality profile is invalid ', 10);

        Http::preventStrayRequests();
        Http::fake([
            'http://radarr.test/api/v3/rootfolder' => Http::response([
                ['path' => '/movies', 'freeSpace' => 100 * 1024 * 1024 * 1024],
            ]),
            'http://radarr.test/api/v3/diskspace' => Http::response([
                ['path' => '/', 'freeSpace' => 100 * 1024 * 1024 * 1024],
            ]),
            'http://radarr.test/api/v3/movie' => Http::response([
                ['propertyName' => 'QualityProfileId', 'errorMessage' => $sourceError],
            ], 400),
        ]);

        app(LibrarySyncService::class)->processWishlist();

        $log = $item->logs()->latest()->firstOrFail();
        $this->assertStringContainsString($sourceError, $log->message);
        $this->assertSame(json_encode([['propertyName' => 'QualityProfileId', 'errorMessage' => $sourceError]]), $log->context['source_response']);
        $this->assertSame(400, $log->context['status']);
    }

    public function test_wishlist_processing_is_scheduled_hourly_without_overlapping(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($event): bool => str_contains($event->command, 'wishlist:process'));

        $this->assertNotNull($event);
        $this->assertSame('0 * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }

    public function test_activity_logs_can_be_used_for_general_media_item_activity(): void
    {
        $mediaItem = MediaItem::query()->create([
            'source' => Integration::RADARR,
            'external_id' => '123',
            'type' => 'movie',
            'title' => 'A movie',
            'sort_title' => 'A movie',
            'is_monitored' => true,
            'is_available' => false,
            'source_metadata' => [],
        ]);

        $log = ActivityLog::factory()
            ->for($mediaItem, 'subject')
            ->create(['event' => 'media.synced']);

        $this->assertTrue($mediaItem->logs()->firstOrFail()->is($log));
        $this->assertSame('media.synced', $log->event);
    }

    private function admin(): User
    {
        $admin = User::factory()->create();
        $role = Role::query()->firstOrCreate(['name' => 'admin']);
        $admin->roles()->attach($role);

        return $admin;
    }

    /**
     * @return array<string, mixed>
     */
    private function integrationAttributes(string $source, string $baseUrl = 'http://radarr.test', ?int $qualityProfileId = null): array
    {
        return ['source' => $source, 'base_url' => $baseUrl, 'api_key' => 'secret-key', 'default_quality_profile_id' => $qualityProfileId];
    }

    /**
     * @return array{source: string, external_id: string, type: string, title: string, poster_url: string|null, imdb_rating: float|null, overview: string|null, payload: array<string, mixed>}
     */
    private function lookupResult(): array
    {
        return [
            'source' => Integration::RADARR,
            'external_id' => '321',
            'type' => 'movie',
            'title' => 'A movie',
            'poster_url' => null,
            'imdb_rating' => 8.6,
            'overview' => 'A synopsis for the wishlist result.',
            'payload' => [
                'title' => 'A movie',
                'tmdbId' => 321,
                'qualityProfileId' => 1,
                'overview' => 'A synopsis for the wishlist result.',
                'ratings' => ['imdb' => ['value' => 8.6]],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function wishlistAttributes(array $overrides = []): array
    {
        return [...[
            'source' => Integration::RADARR,
            'external_id' => '321',
            'type' => 'movie',
            'title' => 'A movie',
            'poster_url' => null,
            'status' => WishlistItem::PENDING,
            'source_payload' => ['title' => 'A movie', 'tmdbId' => 321, 'qualityProfileId' => 1],
        ], ...$overrides];
    }
}
