<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventMedia extends Model
{
    protected $table = 'event_media';

    protected $fillable = ['event_id', 'source_type', 'url', 'is_cover', 'sort_order'];

    protected $casts = ['is_cover' => 'boolean'];

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }
}
