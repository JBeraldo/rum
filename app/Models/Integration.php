<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;

/**
 * @property string $source
 * @property string $base_url
 * @property string $api_key
 * @property int|null $default_quality_profile_id
 * @property Carbon|null $last_tested_at
 * @property Carbon|null $last_synced_at
 * @property string|null $last_error
 */
#[Fillable(['source', 'base_url', 'api_key', 'default_quality_profile_id', 'last_tested_at', 'last_synced_at', 'last_error'])]
#[Hidden(['api_key'])]
class Integration extends Model
{
    public const RADARR = 'radarr';

    public const SONARR = 'sonarr';

    /**
     * Get this integration's activity log.
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
            'api_key' => 'encrypted',
            'default_quality_profile_id' => 'integer',
            'last_tested_at' => 'datetime',
            'last_synced_at' => 'datetime',
        ];
    }
}
