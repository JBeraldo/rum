<?php

namespace App\Observers;

use App\Models\MediaItemLog;
use App\Services\DiscordWebhookService;

class MediaItemLogObserver
{
    public function __construct(private DiscordWebhookService $discordWebhookService) {}

    /**
     * Handle the MediaItemLog "created" event.
     */
    public function created(MediaItemLog $mediaItemLog): void
    {
        $this->discordWebhookService->notify($mediaItemLog);
    }
}
