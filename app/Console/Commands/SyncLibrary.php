<?php

namespace App\Console\Commands;

use App\Models\Integration;
use App\Services\LibrarySyncService;
use Illuminate\Console\Command;

class SyncLibrary extends Command
{
    protected $signature = 'library:sync';

    protected $description = 'Sync configured Radarr and Sonarr catalogs';

    public function handle(LibrarySyncService $librarySync): int
    {
        $successful = true;

        Integration::query()->each(function (Integration $integration) use ($librarySync, &$successful): void {
            if ($librarySync->sync($integration)) {
                $this->info("Synced {$integration->source}.");

                return;
            }

            $successful = false;
            $this->error("Unable to sync {$integration->source}.");
        });

        return $successful ? self::SUCCESS : self::FAILURE;
    }
}
