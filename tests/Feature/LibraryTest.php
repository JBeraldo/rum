<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LibraryTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page(): void
    {
        $response = $this->get(route('library'));

        $response->assertRedirect(route('login'));
    }

    public function test_authenticated_users_can_visit_the_library_from_the_menu(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $response = $this->get(route('library'));

        $response
            ->assertOk()
            ->assertSee('Library')
            ->assertSee(route('library'), false);
    }
}
