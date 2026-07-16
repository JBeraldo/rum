<?php

namespace Database\Seeders;

use App\Models\DownloadClient;
use Illuminate\Database\Seeder;

class DownloadClientSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        DownloadClient::factory()->create();
    }
}
