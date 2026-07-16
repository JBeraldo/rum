<?php

namespace App\Console\Commands;

use App\Models\DownloadClient;
use App\Services\DownloadSyncService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('downloads:sync')]
#[Description('Synchronize media-linked transfers from qBittorrent')]
class SyncDownloads extends Command
{
    public function handle(DownloadSyncService $downloadSync): int
    {
        $client = DownloadClient::query()->where('type', DownloadClient::QBITTORRENT)->first();

        $hasCredentials = $client !== null
            && (filled($client->api_key) || (filled($client->username) && filled($client->password)));

        if (! $hasCredentials) {
            $this->components->info('qBittorrent is not configured.');

            return self::SUCCESS;
        }

        $result = $downloadSync->sync($client);

        if (! $result['successful']) {
            $this->components->error($client->fresh()->last_error ?? 'Download synchronization failed.');

            return self::FAILURE;
        }

        $this->components->info("Tracked {$result['tracked']} transfers; {$result['active']} active; {$result['removed']} removed.");

        return self::SUCCESS;
    }
}
