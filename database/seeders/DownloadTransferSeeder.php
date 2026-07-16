<?php

namespace Database\Seeders;

use App\Models\DownloadTransfer;
use Illuminate\Database\Seeder;

class DownloadTransferSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        DownloadTransfer::factory()->count(3)->create();
    }
}
