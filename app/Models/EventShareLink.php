<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventShareLink extends Model
{
    protected $fillable = [
        'event_id',
        'label',
        'source_code',
        'is_system',
    ];

    protected $casts = [
        'is_system' => 'boolean',
    ];

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }
}
