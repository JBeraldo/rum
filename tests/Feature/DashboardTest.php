<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\MediaItem;
use App\Models\User;
use App\Models\WishlistItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page(): void
    {
        $response = $this->get(route('dashboard'));
        $response->assertRedirect(route('login'));
    }

    public function test_authenticated_users_can_visit_the_dashboard(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $response = $this->get(route('dashboard'));
        $response->assertOk();
    }

    public function test_dashboard_displays_library_wishlist_and_recent_activity_statistics(): void
    {
        $user = User::factory()->create();
        $availableMovie = MediaItem::query()->create([
            'source' => 'radarr',
            'external_id' => 'movie-1',
            'type' => 'movie',
            'title' => 'Available movie',
            'sort_title' => 'Available movie',
            'is_monitored' => true,
            'is_available' => true,
            'source_metadata' => [],
        ]);
        MediaItem::query()->create([
            'source' => 'sonarr',
            'external_id' => 'series-1',
            'type' => 'series',
            'title' => 'Missing series',
            'sort_title' => 'Missing series',
            'is_monitored' => true,
            'is_available' => false,
            'source_metadata' => [],
        ]);
        WishlistItem::query()->create([
            'source' => 'radarr',
            'external_id' => 'wish-1',
            'type' => 'movie',
            'title' => 'Pending wish',
            'status' => WishlistItem::PENDING,
            'source_payload' => [],
        ]);
        ActivityLog::factory()
            ->for($availableMovie, 'subject')
            ->create(['event' => 'media.synced', 'message' => 'Library sync completed.']);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Library titles')
            ->assertSee('Available now')
            ->assertSee('Shared wishlist')
            ->assertSee('Recent activity')
            ->assertSee('Available movie')
            ->assertSee('Library sync completed.');
    }
}
