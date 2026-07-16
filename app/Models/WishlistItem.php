<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;

#[Fillable([
    'source',
    'external_id',
    'type',
    'title',
    'poster_url',
    'status',
    'selected_root_folder',
    'source_item_id',
    'requested_at',
    'source_payload',
])]
class WishlistItem extends Model
{
    public const PENDING = 'pending';

    public const REQUESTED = 'requested';

    public const FAILED = 'failed';

    protected function casts(): array
    {
        return [
            'requested_at' => 'datetime',
            'source_payload' => 'array',
        ];
    }

    /**
     * Get the members that requested this title.
     *
     * @return HasMany<WishlistRequester, $this>
     */
    public function requesters(): HasMany
    {
        return $this->hasMany(WishlistRequester::class);
    }

    /**
     * Get the current user's request association when it has been eager loaded.
     *
     * @return HasOne<WishlistRequester, $this>
     */
    public function currentRequester(): HasOne
    {
        return $this->hasOne(WishlistRequester::class);
    }

    /**
     * Get this wishlist item's processing history.
     *
     * @return MorphMany<ActivityLog, $this>
     */
    public function logs(): MorphMany
    {
        return $this->morphMany(ActivityLog::class, 'subject');
    }

    /**
     * Get the latest processing log entry.
     *
     * @return MorphOne<ActivityLog, $this>
     */
    public function latestLog(): MorphOne
    {
        return $this->morphOne(ActivityLog::class, 'subject')->latestOfMany();
    }

    /**
     * Get the latest processing error or deferral.
     *
     * @return MorphOne<ActivityLog, $this>
     */
    public function latestError(): MorphOne
    {
        return $this->morphOne(ActivityLog::class, 'subject')
            ->where('event', 'wishlist.deferred')
            ->latestOfMany();
    }

    /**
     * Get downloads matched to this wishlist item.
     *
     * @return HasMany<DownloadTransfer, $this>
     */
    public function downloadTransfers(): HasMany
    {
        return $this->hasMany(DownloadTransfer::class);
    }

    /**
     * Get the newest active download for this wishlist item.
     *
     * @return HasOne<DownloadTransfer, $this>
     */
    public function activeDownloadTransfer(): HasOne
    {
        return $this->hasOne(DownloadTransfer::class)->active()->latestOfMany();
    }
}
