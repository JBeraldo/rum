<?php

namespace App\Models;

use Database\Factories\DiscordWebhookFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $webhook_url
 * @property array<int, string> $events
 */
#[Fillable(['webhook_url', 'events'])]
#[Hidden(['webhook_url'])]
class DiscordWebhook extends Model
{
    /** @use HasFactory<DiscordWebhookFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'webhook_url' => 'encrypted',
            'events' => 'array',
        ];
    }
}
