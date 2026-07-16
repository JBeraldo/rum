<?php

namespace Database\Factories;

use App\Models\DownloadClient;
use App\Models\DownloadTransfer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DownloadTransfer>
 */
class DownloadTransferFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'download_client_id' => DownloadClient::factory(),
            'source' => 'radarr',
            'source_item_id' => (string) fake()->numberBetween(1, 1000),
            'torrent_hash' => fake()->unique()->sha1(),
            'name' => fake()->sentence(3),
            'progress' => fake()->randomFloat(4, 0, 0.99),
            'state' => 'downloading',
            'download_speed' => fake()->numberBetween(100_000, 10_000_000),
            'eta_seconds' => fake()->numberBetween(60, 14_400),
            'size_bytes' => fake()->numberBetween(1_000_000_000, 100_000_000_000),
            'amount_left_bytes' => fake()->numberBetween(1_000_000, 1_000_000_000),
            'last_seen_at' => now(),
        ];
    }
}
