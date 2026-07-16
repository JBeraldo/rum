<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\DiscordWebhook;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

class DiscordWebhookService
{
    /** @var array<string, string> */
    public const EVENTS = [
        'wishlist.requested' => 'Wishlist requested',
        'wishlist.deferred' => 'Wishlist deferred',
        'download.started' => 'Download started',
        'download.completed' => 'Download completed',
        'download.failed' => 'Download failed',
        'download.removed' => 'Download removed',
        'download.sync_failed' => 'Download sync failed',
        'library.synced' => 'Library synced',
        'library.sync_failed' => 'Library sync failed',
    ];

    /**
     * Verify that the webhook accepts messages.
     */
    public function test(DiscordWebhook $webhook): void
    {
        $this->post($webhook, 'Rum webhook connected.');
    }

    /**
     * Deliver a configured activity event without allowing delivery failures to interrupt the activity workflow.
     */
    public function notify(ActivityLog $log): void
    {
        $webhook = DiscordWebhook::query()->first();

        if ($webhook === null || ! in_array($log->event, $webhook->events, true)) {
            return;
        }

        try {
            $this->post($webhook, $this->message($log));
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    public static function isDiscordWebhookUrl(string $webhookUrl): bool
    {
        $parts = parse_url($webhookUrl);
        $host = is_array($parts) && isset($parts['host']) ? Str::lower($parts['host']) : null;
        $path = is_array($parts) && isset($parts['path']) ? $parts['path'] : null;

        return is_array($parts)
            && ($parts['scheme'] ?? null) === 'https'
            && in_array($host, ['discord.com', 'canary.discord.com', 'ptb.discord.com', 'discordapp.com', 'canary.discordapp.com', 'ptb.discordapp.com'], true)
            && is_string($path)
            && Str::startsWith($path, '/api/webhooks/');
    }

    private function post(DiscordWebhook $webhook, string $content): void
    {
        Http::connectTimeout(3)
            ->timeout(10)
            ->retry([100, 300], throw: false)
            ->post($webhook->webhook_url, ['content' => $content])
            ->throw();
    }

    private function message(ActivityLog $log): string
    {
        $event = self::EVENTS[$log->event] ?? Str::headline($log->event);
        $message = $log->message ?? 'Activity recorded.';

        return Str::limit("**Rum · {$event}**\n{$message}", 2_000, '');
    }
}
