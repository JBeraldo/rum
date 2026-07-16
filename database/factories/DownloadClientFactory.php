<?php

namespace Database\Factories;

use App\Models\DownloadClient;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DownloadClient>
 */
class DownloadClientFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'type' => DownloadClient::QBITTORRENT,
            'base_url' => 'http://qbittorrent.test',
            'username' => 'admin',
            'password' => 'change-me',
            'api_key' => 'qbt_'.fake()->regexify('[A-Za-z0-9]{28}'),
            'last_tested_at' => now(),
        ];
    }
}
