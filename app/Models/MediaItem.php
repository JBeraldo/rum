<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;

#[Fillable([
    'source',
    'external_id',
    'type',
    'title',
    'sort_title',
    'year',
    'overview',
    'poster_url',
    'is_monitored',
    'is_available',
    'source_metadata',
])]
class MediaItem extends Model
{
    protected function casts(): array
    {
        return [
            'is_monitored' => 'boolean',
            'is_available' => 'boolean',
            'source_metadata' => 'array',
        ];
    }

    /**
     * Get this media item's activity log.
     *
     * @return MorphMany<ActivityLog, $this>
     */
    public function logs(): MorphMany
    {
        return $this->morphMany(ActivityLog::class, 'subject');
    }

    /**
     * Get downloads matched to this media item.
     *
     * @return HasMany<DownloadTransfer, $this>
     */
    public function downloadTransfers(): HasMany
    {
        return $this->hasMany(DownloadTransfer::class);
    }

    /**
     * Get the newest active download for this media item.
     *
     * @return HasOne<DownloadTransfer, $this>
     */
    public function activeDownloadTransfer(): HasOne
    {
        return $this->hasOne(DownloadTransfer::class)->active()->latestOfMany();
    }

    /**
     * Limit items to a media type when provided.
     *
     * @param  Builder<MediaItem>  $query
     * @return Builder<MediaItem>
     */
    public function scopeOfType(Builder $query, ?string $type): Builder
    {
        return $type === null || $type === '' ? $query : $query->where('type', $type);
    }
}
