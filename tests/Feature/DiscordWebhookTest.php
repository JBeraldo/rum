<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\DiscordWebhook;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class DiscordWebhookTest extends TestCase
{
    use RefreshDatabase;

    public function test_selected_activity_events_are_sent_to_the_discord_webhook(): void
    {
        DiscordWebhook::factory()->create(['events' => ['download.completed']]);

        Http::preventStrayRequests();
        Http::fake(['https://discord.com/api/webhooks/*' => Http::response([], 204)]);

        ActivityLog::query()->create([
            'event' => 'download.completed',
            'message' => 'The download completed.',
            'context' => [],
        ]);

        Http::assertSent(function (Request $request): bool {
            return $request->url() === 'https://discord.com/api/webhooks/1234567890/example-token'
                && str_contains((string) $request['content'], 'Download completed')
                && str_contains((string) $request['content'], 'The download completed.');
        });
    }

    public function test_unselected_activity_events_are_not_sent_to_discord(): void
    {
        DiscordWebhook::factory()->create(['events' => ['download.completed']]);

        Http::preventStrayRequests();

        ActivityLog::query()->create([
            'event' => 'wishlist.deferred',
            'message' => 'Not enough free space.',
            'context' => [],
        ]);

        Http::assertNothingSent();
    }

    public function test_administrators_can_save_an_encrypted_discord_webhook_and_select_events(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://discord.com/api/webhooks/*' => Http::response([], 204)]);

        Livewire::actingAs($this->admin())
            ->test('pages::settings.integrations')
            ->set('discordWebhookUrl', 'https://discord.com/api/webhooks/1234567890/example-token')
            ->set('discordEvents', ['download.completed', 'wishlist.requested'])
            ->call('saveDiscordWebhook')
            ->assertHasNoErrors();

        $webhook = DiscordWebhook::query()->firstOrFail();

        $this->assertSame('https://discord.com/api/webhooks/1234567890/example-token', $webhook->webhook_url);
        $this->assertNotSame('https://discord.com/api/webhooks/1234567890/example-token', $webhook->getRawOriginal('webhook_url'));
        $this->assertSame(['download.completed', 'wishlist.requested'], $webhook->events);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://discord.com/api/webhooks/1234567890/example-token'
            && $request['content'] === 'Rum webhook connected.');
    }

    private function admin(): User
    {
        $admin = User::factory()->create();
        $role = Role::query()->firstOrCreate(['name' => 'admin']);
        $admin->roles()->attach($role);

        return $admin;
    }
}
