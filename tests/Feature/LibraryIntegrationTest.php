<?php

namespace Tests\Feature;

use App\Models\Integration;
use App\Models\MediaItem;
use App\Models\Role;
use App\Models\User;
use App\Services\LibrarySyncService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class LibraryIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_administrators_can_manage_integrations_and_users(): void
    {
        $member = User::factory()->create();

        $this->actingAs($member)
            ->get(route('integrations.edit'))
            ->assertForbidden();

        $this->actingAs($member)
            ->get(route('users.edit'))
            ->assertForbidden();

        $admin = $this->admin();

        $this->actingAs($admin)->get(route('integrations.edit'))->assertOk();
        $this->actingAs($admin)->get(route('users.edit'))->assertOk();
    }

    public function test_an_administrator_can_save_a_verified_connection_without_exposing_the_api_key(): void
    {
        Http::preventStrayRequests();
        Http::fake(['http://radarr.test/api/v3/system/status' => Http::response(['version' => '5.0'])]);

        Livewire::actingAs($this->admin())
            ->test('pages::settings.integrations')
            ->set('radarrBaseUrl', 'http://radarr.test')
            ->set('radarrApiKey', 'secret-key')
            ->call('saveRadarr')
            ->assertHasNoErrors();

        $integration = Integration::query()->where('source', Integration::RADARR)->firstOrFail();

        $this->assertSame('secret-key', $integration->api_key);
        $this->assertNotSame('secret-key', $integration->getRawOriginal('api_key'));
        $this->assertNotNull($integration->last_tested_at);
        Http::assertSent(fn ($request): bool => $request->hasHeader('X-Api-Key', 'secret-key'));
    }

    public function test_invalid_connection_credentials_are_not_persisted(): void
    {
        Http::preventStrayRequests();
        Http::fake(['http://radarr.test/api/v3/system/status' => Http::response([], 401)]);

        Livewire::actingAs($this->admin())
            ->test('pages::settings.integrations')
            ->set('radarrBaseUrl', 'http://radarr.test')
            ->set('radarrApiKey', 'incorrect-key')
            ->call('saveRadarr')
            ->assertHasErrors('radarrApiKey');

        $this->assertNull(Integration::query()->where('source', Integration::RADARR)->first());
    }

    public function test_an_administrator_can_load_and_save_a_default_quality_profile(): void
    {
        Integration::query()->create([
            'source' => Integration::RADARR,
            'base_url' => 'http://radarr.test',
            'api_key' => 'secret-key',
        ]);

        Http::preventStrayRequests();
        Http::fake([
            'http://radarr.test/api/v3/qualityprofile' => Http::response([
                ['id' => 3, 'name' => 'HD-1080p'],
                ['id' => 4, 'name' => 'Ultra-HD'],
            ]),
            'http://radarr.test/api/v3/system/status' => Http::response(['version' => '5.0']),
        ]);

        Livewire::actingAs($this->admin())
            ->test('pages::settings.integrations')
            ->call('loadQualityProfiles', Integration::RADARR)
            ->assertSet('radarrQualityProfiles', [
                ['id' => 3, 'name' => 'HD-1080p'],
                ['id' => 4, 'name' => 'Ultra-HD'],
            ])
            ->assertSet('radarrQualityProfileId', '3')
            ->set('radarrQualityProfileId', '4')
            ->call('saveRadarr')
            ->assertHasNoErrors();

        $this->assertSame(4, Integration::query()->where('source', Integration::RADARR)->value('default_quality_profile_id'));
    }

    public function test_a_failed_sync_preserves_existing_items_and_records_the_error(): void
    {
        $integration = Integration::query()->create([
            'source' => Integration::RADARR,
            'base_url' => 'http://radarr.test',
            'api_key' => 'secret-key',
        ]);
        $item = MediaItem::query()->create($this->itemAttributes(['external_id' => '10']));

        Http::preventStrayRequests();
        Http::fake(['http://radarr.test/api/v3/movie' => Http::response([], 500)]);

        $this->assertFalse(app(LibrarySyncService::class)->sync($integration));
        $this->assertModelExists($item);
        $this->assertNotNull($integration->refresh()->last_error);
        $this->assertSame(1, $integration->logs()->where('event', 'library.sync_failed')->count());
    }

    public function test_sync_maps_items_and_removes_stale_items_after_a_successful_response(): void
    {
        $integration = Integration::query()->create([
            'source' => Integration::SONARR,
            'base_url' => 'http://sonarr.test',
            'api_key' => 'secret-key',
        ]);
        $staleItem = MediaItem::query()->create($this->itemAttributes([
            'source' => Integration::SONARR,
            'external_id' => '99',
            'type' => 'series',
        ]));

        Http::preventStrayRequests();
        Http::fake(['http://sonarr.test/api/v3/series' => Http::response([[
            'id' => 7,
            'title' => 'The Show',
            'sortTitle' => 'Show, The',
            'year' => 2024,
            'overview' => 'A series.',
            'monitored' => true,
            'statistics' => ['episodeFileCount' => 2],
            'images' => [['coverType' => 'poster', 'remoteUrl' => 'https://image.test/show.jpg']],
        ]])]);

        $this->assertTrue(app(LibrarySyncService::class)->sync($integration));

        $item = MediaItem::query()->where('source', Integration::SONARR)->where('external_id', '7')->firstOrFail();
        $this->assertSame('series', $item->type);
        $this->assertTrue($item->is_available);
        $this->assertSame('https://image.test/show.jpg', $item->poster_url);
        $this->assertNull($staleItem->fresh());
        $this->assertNotNull($integration->refresh()->last_synced_at);
        $this->assertSame(1, $integration->logs()->where('event', 'library.synced')->count());
    }

    public function test_library_is_visible_to_members_and_can_be_filtered(): void
    {
        MediaItem::query()->create($this->itemAttributes(['title' => 'Arrival', 'sort_title' => 'Arrival', 'is_available' => true]));
        MediaItem::query()->create($this->itemAttributes(['external_id' => '2', 'title' => 'Missing Film', 'sort_title' => 'Missing Film']));

        Livewire::actingAs(User::factory()->create())
            ->test('pages::library')
            ->set('search', 'Arrival')
            ->set('availability', 'available')
            ->assertSee('Arrival')
            ->assertDontSee('Missing Film');
    }

    public function test_library_uses_poster_tiles_that_link_to_rich_item_details(): void
    {
        $item = MediaItem::query()->create($this->itemAttributes([
            'title' => 'Arrival',
            'poster_url' => 'https://image.test/arrival.jpg',
            'overview' => 'A linguist communicates with alien visitors.',
            'source_metadata' => [
                'genres' => ['Drama', 'Science Fiction'],
                'runtime' => 116,
                'ratings' => ['imdb' => ['value' => 8.1]],
                'studio' => 'Paramount Pictures',
                'tmdbId' => 329865,
                'originalLanguage' => ['name' => 'English'],
            ],
        ]));

        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('library'))
            ->assertOk()
            ->assertSee('library-poster-'.$item->id, false)
            ->assertSee(route('library.show', $item), false)
            ->assertDontSee('Delete from Radarr');

        $this->actingAs($this->admin())
            ->get(route('library'))
            ->assertOk()
            ->assertSee('Options')
            ->assertSee('Delete from Radarr');

        $this->actingAs($user)
            ->get(route('library.show', $item))
            ->assertOk()
            ->assertSee('Arrival')
            ->assertSee('A linguist communicates with alien visitors.')
            ->assertSee('Drama, Science Fiction')
            ->assertSee('116 min')
            ->assertSee('Paramount Pictures')
            ->assertSee('English');
    }

    public function test_administrator_can_delete_an_item_and_its_files_from_radarr_after_confirming_its_title(): void
    {
        $integration = Integration::query()->create([
            'source' => Integration::RADARR,
            'base_url' => 'http://radarr.test',
            'api_key' => 'secret-key',
        ]);
        $item = MediaItem::query()->create($this->itemAttributes(['title' => 'Arrival']));

        Http::preventStrayRequests();
        Http::fake(['http://radarr.test/api/v3/movie/1*' => Http::response([], 200)]);

        Livewire::actingAs($this->admin())
            ->test('pages::library')
            ->call('requestDeletion', $item->id)
            ->set('deleteConfirmation', 'Wrong title')
            ->call('delete')
            ->assertHasErrors('deleteConfirmation');

        $this->assertModelExists($item);

        Livewire::actingAs($this->admin())
            ->test('pages::library')
            ->call('requestDeletion', $item->id)
            ->set('deleteConfirmation', 'Arrival')
            ->call('delete')
            ->assertRedirect(route('library'));

        $this->assertModelMissing($item);
        Http::assertSent(function ($request): bool {
            return $request->method() === 'DELETE'
                && str_contains($request->url(), '/api/v3/movie/1')
                && str_contains($request->url(), 'deleteFiles=true')
                && str_contains($request->url(), 'addImportExclusion=false');
        });

        $this->assertNotNull($integration);
    }

    public function test_failed_source_deletion_keeps_the_local_item(): void
    {
        Integration::query()->create([
            'source' => Integration::SONARR,
            'base_url' => 'http://sonarr.test',
            'api_key' => 'secret-key',
        ]);
        $item = MediaItem::query()->create($this->itemAttributes([
            'source' => Integration::SONARR,
            'type' => 'series',
            'title' => 'The Show',
        ]));

        Http::preventStrayRequests();
        Http::fake(['http://sonarr.test/api/v3/series/1*' => Http::response([], 500)]);

        Livewire::actingAs($this->admin())
            ->test('pages::library')
            ->call('requestDeletion', $item->id)
            ->set('deleteConfirmation', 'The Show')
            ->call('delete')
            ->assertHasNoErrors();

        $this->assertModelExists($item);
    }

    public function test_role_command_assigns_a_role_to_an_existing_user(): void
    {
        $user = User::factory()->create(['email' => 'admin@example.test']);
        Role::query()->create(['name' => 'admin']);

        $this->artisan('users:grant-role admin@example.test admin')
            ->assertSuccessful();

        $this->assertTrue($user->refresh()->isAdmin());
    }

    public function test_library_sync_is_scheduled_hourly_without_overlapping(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($event): bool => str_contains($event->command, 'library:sync'));

        $this->assertNotNull($event);
        $this->assertSame('0 * * * *', $event->expression);
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
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function itemAttributes(array $overrides = []): array
    {
        return [...[
            'source' => Integration::RADARR,
            'external_id' => '1',
            'type' => 'movie',
            'title' => 'A film',
            'sort_title' => 'A film',
            'year' => 2024,
            'overview' => null,
            'poster_url' => null,
            'is_monitored' => true,
            'is_available' => false,
            'source_metadata' => [],
        ], ...$overrides];
    }
}
