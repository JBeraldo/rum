<?php

namespace App\Models;

use App\Observers\MediaItemLogObserver;
use Database\Factories\MediaItemLogFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

#[Fillable(['event', 'message', 'context'])]
#[ObservedBy([MediaItemLogObserver::class])]
class MediaItemLog extends Model
{
    /** @use HasFactory<MediaItemLogFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'context' => 'array',
        ];
    }

    /**
     * Get the model this log entry describes.
     *
     * @return MorphTo<Model, $this>
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }
}
