<?php

namespace App\Observers;

use App\Models\ActivityLog;
use App\Services\DiscordWebhookService;

class ActivityLogObserver
{
    public function __construct(private DiscordWebhookService $discordWebhookService) {}

    /**
     * Handle the ActivityLog "created" event.
     */
    public function created(ActivityLog $activityLog): void
    {
        $this->discordWebhookService->notify($activityLog);
    }
}
