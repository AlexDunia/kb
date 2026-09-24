<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventTrafficView extends Model
{
    protected $fillable = [
        'event_id',
        'user_id',
        'visitor_hash',
        'source_code',
        'view_bucket',
        'viewed_at',
        'ip_hash',
        'user_agent_hash',
    ];

    protected $casts = [
        'view_bucket' => 'datetime',
        'viewed_at' => 'datetime',
    ];

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
