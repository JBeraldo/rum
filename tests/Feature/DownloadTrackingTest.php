<?php

namespace Tests\Feature;

use App\Models\DownloadClient;
use App\Models\DownloadTransfer;
use App\Models\Integration;
use App\Models\MediaItem;
use App\Models\Role;
use App\Models\User;
use App\Models\WishlistItem;
use App\Services\DownloadSyncService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class DownloadTrackingTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_administrator_can_save_an_encrypted_api_key_that_is_preferred_over_password_login(): void
    {
        Http::preventStrayRequests();
        $this->fakeDownloadApis([], [], []);
        $apiKey = 'qbt_1234567890123456789012345678';

        Livewire::actingAs($this->admin())
            ->test('pages::settings.integrations')
            ->set('qbittorrentBaseUrl', 'http://qbittorrent.test')
            ->set('qbittorrentUsername', 'rum')
            ->set('qbittorrentPassword', 'secret-password')
            ->set('qbittorrentApiKey', $apiKey)
            ->call('saveQbittorrent')
            ->assertHasNoErrors();

        $client = DownloadClient::query()->where('type', DownloadClient::QBITTORRENT)->firstOrFail();

        $this->assertSame('rum', $client->username);
        $this->assertSame('secret-password', $client->password);
        $this->assertNotSame('secret-password', $client->getRawOriginal('password'));
        $this->assertSame($apiKey, $client->api_key);
        $this->assertNotSame($apiKey, $client->getRawOriginal('api_key'));
        $this->assertNotNull($client->last_tested_at);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'http://qbittorrent.test/api/v2/app/webapiVersion'
            && $request->hasHeader('Authorization', 'Bearer '.$apiKey));
        Http::assertNotSent(fn (Request $request): bool => $request->url() === 'http://qbittorrent.test/api/v2/auth/login');
    }

    public function test_username_and_password_authentication_remains_available_as_a_fallback(): void
    {
        $client = DownloadClient::factory()->create([
            'api_key' => null,
            'username' => 'rum',
            'password' => 'secret-password',
        ]);

        Http::preventStrayRequests();
        $this->fakeDownloadApis([], [], []);

        $version = app(DownloadSyncService::class)->test($client);

        $this->assertSame('2.11.4', $version);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'http://qbittorrent.test/api/v2/auth/login'
            && $request['username'] === 'rum'
            && $request['password'] === 'secret-password');
        Http::assertSent(fn (Request $request): bool => $request->url() === 'http://qbittorrent.test/api/v2/app/webapiVersion'
            && $request->hasHeader('Cookie', 'SID=test-session'));
    }

    public function test_synchronization_maps_radarr_and_sonarr_downloads_by_hash_and_ignores_unmatched_torrents(): void
    {
        $client = DownloadClient::factory()->create();
        Integration::query()->create($this->integrationAttributes(Integration::RADARR, 'http://radarr.test'));
        Integration::query()->create($this->integrationAttributes(Integration::SONARR, 'http://sonarr.test'));
        $movie = MediaItem::query()->create($this->mediaAttributes(['external_id' => '10', 'title' => 'Tracked movie']));
        $seriesWish = WishlistItem::query()->create($this->wishlistAttributes([
            'source' => Integration::SONARR,
            'external_id' => 'tvdb-20',
            'type' => 'series',
            'title' => 'Tracked series',
            'source_item_id' => '20',
        ]));

        Http::preventStrayRequests();
        $this->fakeDownloadApis(
            [
                $this->torrent('abcdef', 'Tracked movie download', 0.42),
                $this->torrent('123abc', 'Tracked series download', 0.75),
                $this->torrent('unmatched', 'Unrelated torrent', 0.25),
            ],
            [['downloadId' => 'ABCDEF', 'movieId' => 10]],
            [['downloadId' => '123ABC', 'seriesId' => 20]],
        );

        $result = app(DownloadSyncService::class)->sync($client);

        $this->assertTrue($result['successful']);
        $this->assertSame(2, $result['tracked']);
        $this->assertSame(2, DownloadTransfer::query()->count());

        $movieTransfer = DownloadTransfer::query()->where('torrent_hash', 'abcdef')->firstOrFail();
        $seriesTransfer = DownloadTransfer::query()->where('torrent_hash', '123abc')->firstOrFail();
        $this->assertTrue($movieTransfer->mediaItem->is($movie));
        $this->assertTrue($seriesTransfer->wishlistItem->is($seriesWish));
        $this->assertSame(42.0, $movieTransfer->progressPercentage());
        $this->assertSame(1, $movie->logs()->where('event', 'download.started')->count());
        $this->assertSame(1, $seriesWish->logs()->where('event', 'download.started')->count());
        $this->assertNull(DownloadTransfer::query()->where('torrent_hash', 'unmatched')->first());
        $this->assertNotNull($client->refresh()->last_synced_at);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'http://qbittorrent.test/api/v2/torrents/info'
            && $request->hasHeader('Authorization', 'Bearer '.$client->api_key));
    }

    public function test_completion_is_logged_once_and_linkage_survives_after_the_source_queue_entry_disappears(): void
    {
        $client = DownloadClient::factory()->create();
        Integration::query()->create($this->integrationAttributes(Integration::RADARR, 'http://radarr.test'));
        $movie = MediaItem::query()->create($this->mediaAttributes(['external_id' => '10']));

        Http::preventStrayRequests();
        $completedTorrent = $this->torrent('abcdef', 'Movie download', 1);
        $completedTorrent['completion_on'] = now()->timestamp;
        $torrentCalls = 0;
        $queueCalls = 0;
        $librarySyncCalls = 0;
        Http::fake(function (Request $request) use ($completedTorrent, &$torrentCalls, &$queueCalls, &$librarySyncCalls) {
            return match (true) {
                $request->url() === 'http://qbittorrent.test/api/v2/auth/login' => Http::response('Ok.', 200, ['Set-Cookie' => 'SID=test-session; HttpOnly; path=/']),
                $request->url() === 'http://qbittorrent.test/api/v2/torrents/info' => Http::response($torrentCalls++ === 0
                    ? [$this->torrent('abcdef', 'Movie download', 0.5)]
                    : [$completedTorrent]),
                str_starts_with($request->url(), 'http://radarr.test/api/v3/queue') => Http::response(['records' => $queueCalls++ === 0
                    ? [['downloadId' => 'ABCDEF', 'movieId' => 10]]
                    : []]),
                $request->url() === 'http://radarr.test/api/v3/movie' => tap(
                    Http::response([$this->radarrMovie()]),
                    function () use (&$librarySyncCalls): void {
                        $librarySyncCalls++;
                    },
                ),
                default => Http::response([], 404),
            };
        });

        app(DownloadSyncService::class)->sync($client);
        app(DownloadSyncService::class)->sync($client);
        app(DownloadSyncService::class)->sync($client);
        app(DownloadSyncService::class)->sync($client);

        $transfer = DownloadTransfer::query()->where('torrent_hash', 'abcdef')->firstOrFail();
        $this->assertTrue($transfer->mediaItem->is($movie));
        $this->assertSame(100.0, $transfer->progressPercentage());
        $this->assertNotNull($transfer->completed_at);
        $this->assertSame(1, $movie->logs()->where('event', 'download.completed')->count());
        $this->assertSame(1, $librarySyncCalls);
    }

    public function test_source_errors_preserve_transfers_and_store_the_complete_response_without_duplicate_failure_logs(): void
    {
        $client = DownloadClient::factory()->create();
        Integration::query()->create($this->integrationAttributes(Integration::RADARR, 'http://radarr.test'));
        $transfer = DownloadTransfer::factory()->for($client)->create([
            'torrent_hash' => 'abcdef',
            'progress' => 0.4,
        ]);
        $marker = str_repeat('full-source-response-', 100);

        Http::preventStrayRequests();
        Http::fake([
            'http://radarr.test/api/v3/queue*' => Http::response(['detail' => $marker], 400),
        ]);

        $firstResult = app(DownloadSyncService::class)->sync($client);
        $secondResult = app(DownloadSyncService::class)->sync($client);

        $this->assertFalse($firstResult['successful']);
        $this->assertFalse($secondResult['successful']);
        $this->assertModelExists($transfer);
        $this->assertSame(40.0, $transfer->refresh()->progressPercentage());
        $this->assertNull($transfer->removed_at);
        $this->assertStringContainsString($marker, $client->refresh()->last_error);
        $this->assertSame(1, $client->logs()->where('event', 'download.sync_failed')->count());
    }

    public function test_transfers_missing_from_qbittorrent_are_retained_as_removed_and_logged(): void
    {
        $client = DownloadClient::factory()->create();
        $movie = MediaItem::query()->create($this->mediaAttributes());
        $transfer = DownloadTransfer::factory()->for($client)->for($movie)->create([
            'torrent_hash' => 'abcdef',
            'source' => Integration::RADARR,
            'source_item_id' => $movie->external_id,
        ]);

        Http::preventStrayRequests();
        $this->fakeDownloadApis([], [], []);

        $result = app(DownloadSyncService::class)->sync($client);

        $this->assertTrue($result['successful']);
        $this->assertSame(1, $result['removed']);
        $this->assertSame('removed', $transfer->refresh()->state);
        $this->assertNotNull($transfer->removed_at);
        $this->assertSame(1, $movie->logs()->where('event', 'download.removed')->count());
    }

    public function test_active_downloads_are_visible_on_the_dashboard_library_wishlist_and_media_details(): void
    {
        $user = User::factory()->create();
        $client = DownloadClient::factory()->create();
        $movie = MediaItem::query()->create($this->mediaAttributes(['title' => 'Downloading movie']));
        $wish = WishlistItem::query()->create($this->wishlistAttributes([
            'title' => 'Downloading movie',
            'source_item_id' => $movie->external_id,
        ]));
        DownloadTransfer::factory()->for($client)->for($movie)->for($wish)->create([
            'progress' => 0.42,
            'source' => $movie->source,
            'source_item_id' => $movie->external_id,
        ]);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Active downloads')
            ->assertSee('Downloading movie')
            ->assertSee('42%');

        $this->actingAs($user)->get(route('library'))->assertOk()->assertSee('Downloading')->assertSee('42%');
        $this->actingAs($user)->get(route('wishlist'))->assertOk()->assertSee('Downloading')->assertSee('42%');
        $this->actingAs($user)->get(route('library.show', $movie))->assertOk()->assertSee('Downloading')->assertSee('42%');
    }

    public function test_download_sync_is_scheduled_every_minute_without_overlapping(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($event): bool => str_contains($event->command, 'downloads:sync'));

        $this->assertNotNull($event);
        $this->assertSame('* * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }

    private function admin(): User
    {
        $admin = User::factory()->create();
        $role = Role::query()->firstOrCreate(['name' => 'admin']);
        $admin->roles()->attach($role);

        return $admin;
    }

    /**
     * @param  array<int, array<string, mixed>>  $torrents
     * @param  array<int, array<string, mixed>>  $radarrQueue
     * @param  array<int, array<string, mixed>>  $sonarrQueue
     */
    private function fakeDownloadApis(array $torrents, array $radarrQueue, array $sonarrQueue): void
    {
        Http::fake([
            'http://qbittorrent.test/api/v2/auth/login' => Http::response('Ok.', 200, [
                'Set-Cookie' => 'SID=test-session; HttpOnly; path=/',
            ]),
            'http://qbittorrent.test/api/v2/app/webapiVersion' => Http::response('2.11.4'),
            'http://qbittorrent.test/api/v2/torrents/info' => Http::response($torrents),
            'http://radarr.test/api/v3/queue*' => Http::response(['records' => $radarrQueue, 'totalRecords' => count($radarrQueue)]),
            'http://sonarr.test/api/v3/queue*' => Http::response(['records' => $sonarrQueue, 'totalRecords' => count($sonarrQueue)]),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function torrent(string $hash, string $name, float $progress): array
    {
        return [
            'hash' => $hash,
            'name' => $name,
            'progress' => $progress,
            'state' => $progress >= 1 ? 'uploading' : 'downloading',
            'dlspeed' => $progress >= 1 ? 0 : 2_000_000,
            'eta' => $progress >= 1 ? 8_640_000 : 600,
            'size' => 10_000_000_000,
            'amount_left' => $progress >= 1 ? 0 : 5_800_000_000,
            'category' => 'radarr',
            'content_path' => '/downloads/'.$name,
            'added_on' => now()->subMinute()->timestamp,
            'completion_on' => 0,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function radarrMovie(): array
    {
        return [
            'id' => 10,
            'title' => 'Movie',
            'sortTitle' => 'Movie',
            'monitored' => true,
            'hasFile' => true,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function integrationAttributes(string $source, string $baseUrl): array
    {
        return [
            'source' => $source,
            'base_url' => $baseUrl,
            'api_key' => 'secret-key',
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function mediaAttributes(array $overrides = []): array
    {
        return [...[
            'source' => Integration::RADARR,
            'external_id' => '10',
            'type' => 'movie',
            'title' => 'Movie',
            'sort_title' => 'Movie',
            'is_monitored' => true,
            'is_available' => false,
            'source_metadata' => [],
        ], ...$overrides];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function wishlistAttributes(array $overrides = []): array
    {
        return [...[
            'source' => Integration::RADARR,
            'external_id' => 'tmdb-10',
            'type' => 'movie',
            'title' => 'Movie wish',
            'status' => WishlistItem::REQUESTED,
            'source_item_id' => '10',
            'source_payload' => [],
        ], ...$overrides];
    }
}
