<?php

namespace App\Models;

use Database\Factories\DownloadClientFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $type
 * @property string $base_url
 * @property string $username
 * @property string $password
 * @property string|null $api_key
 * @property Carbon|null $last_tested_at
 * @property Carbon|null $last_synced_at
 * @property string|null $last_error
 */
#[Fillable(['type', 'base_url', 'username', 'password', 'api_key', 'last_tested_at', 'last_synced_at', 'last_error'])]
#[Hidden(['password', 'api_key'])]
class DownloadClient extends Model
{
    public const QBITTORRENT = 'qbittorrent';

    /** @use HasFactory<DownloadClientFactory> */
    use HasFactory;

    /**
     * Get the transfers synchronized through this client.
     *
     * @return HasMany<DownloadTransfer, $this>
     */
    public function transfers(): HasMany
    {
        return $this->hasMany(DownloadTransfer::class);
    }

    /**
     * Get synchronization activity for this client.
     *
     * @return MorphMany<ActivityLog, $this>
     */
    public function logs(): MorphMany
    {
        return $this->morphMany(ActivityLog::class, 'subject');
    }

    protected function casts(): array
    {
        return [
            'password' => 'encrypted',
            'api_key' => 'encrypted',
            'last_tested_at' => 'datetime',
            'last_synced_at' => 'datetime',
        ];
    }
}
