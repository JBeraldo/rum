<?php

namespace App\Models;

use Carbon\CarbonInterval;
use Database\Factories\DownloadTransferFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property int $download_client_id
 * @property int|null $media_item_id
 * @property int|null $wishlist_item_id
 * @property string $source
 * @property string $source_item_id
 * @property string $torrent_hash
 * @property string $name
 * @property string $progress
 * @property string $state
 * @property int $download_speed
 * @property int|null $eta_seconds
 * @property int $size_bytes
 * @property int $amount_left_bytes
 * @property string|null $category
 * @property string|null $content_path
 * @property Carbon|null $added_at
 * @property Carbon|null $completed_at
 * @property Carbon $last_seen_at
 * @property Carbon|null $removed_at
 */
#[Fillable([
    'download_client_id',
    'media_item_id',
    'wishlist_item_id',
    'source',
    'source_item_id',
    'torrent_hash',
    'name',
    'progress',
    'state',
    'download_speed',
    'eta_seconds',
    'size_bytes',
    'amount_left_bytes',
    'category',
    'content_path',
    'added_at',
    'completed_at',
    'last_seen_at',
    'removed_at',
])]
class DownloadTransfer extends Model
{
    /** @use HasFactory<DownloadTransferFactory> */
    use HasFactory;

    /**
     * Get the download client that reported this transfer.
     *
     * @return BelongsTo<DownloadClient, $this>
     */
    public function downloadClient(): BelongsTo
    {
        return $this->belongsTo(DownloadClient::class);
    }

    /**
     * Get the matched library item.
     *
     * @return BelongsTo<MediaItem, $this>
     */
    public function mediaItem(): BelongsTo
    {
        return $this->belongsTo(MediaItem::class);
    }

    /**
     * Get the matched wishlist item.
     *
     * @return BelongsTo<WishlistItem, $this>
     */
    public function wishlistItem(): BelongsTo
    {
        return $this->belongsTo(WishlistItem::class);
    }

    /**
     * Limit transfers to downloads that are still active in qBittorrent.
     *
     * @param  Builder<DownloadTransfer>  $query
     * @return Builder<DownloadTransfer>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('removed_at')->where('progress', '<', 1);
    }

    public function progressPercentage(): float
    {
        return round((float) $this->progress * 100, 1);
    }

    public function stateLabel(): string
    {
        return Str::headline($this->state);
    }

    public function formattedEta(): ?string
    {
        if ($this->eta_seconds === null) {
            return null;
        }

        return CarbonInterval::seconds($this->eta_seconds)->cascade()->forHumans(short: true, parts: 2);
    }

    protected function casts(): array
    {
        return [
            'progress' => 'decimal:4',
            'download_speed' => 'integer',
            'eta_seconds' => 'integer',
            'size_bytes' => 'integer',
            'amount_left_bytes' => 'integer',
            'added_at' => 'datetime',
            'completed_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'removed_at' => 'datetime',
        ];
    }
}
