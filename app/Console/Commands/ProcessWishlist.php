<?php

namespace App\Console\Commands;

use App\Services\LibrarySyncService;
use Illuminate\Console\Command;

class ProcessWishlist extends Command
{
    protected $signature = 'wishlist:process';

    protected $description = 'Request pending shared wishlist titles when enough space is available';

    public function handle(LibrarySyncService $librarySync): int
    {
        $result = $librarySync->processWishlist();

        $this->info("Requested {$result['requested']} wishlist item(s); skipped {$result['skipped']}.");

        return self::SUCCESS;
    }
}
