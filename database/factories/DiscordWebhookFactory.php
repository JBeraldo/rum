<?php

namespace Database\Factories;

use App\Models\DiscordWebhook;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DiscordWebhook>
 */
class DiscordWebhookFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'webhook_url' => 'https://discord.com/api/webhooks/1234567890/example-token',
            'events' => ['download.completed'],
        ];
    }
}
