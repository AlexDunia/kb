<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventPublicProfile extends Model
{
    protected $fillable = [
        'event_id', 'summary', 'caption_title', 'caption_description',
        'organiser_bio', 'organiser_image', 'guests', 'schedule',
        'moments', 'faqs', 'crew', 'related_event_ids',
    ];

    protected $casts = [
        'guests' => 'array', 'schedule' => 'array', 'moments' => 'array',
        'faqs' => 'array', 'crew' => 'array', 'related_event_ids' => 'array',
    ];

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }
}
