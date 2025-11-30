<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventView extends Model
{
    protected $table = 'event_views';

    protected $fillable = [
        'user_id',
        'event_id',
        'viewed_at',
        'ip_address'
    ];

    protected $casts = [
        'viewed_at' => 'datetime',
        'user_id' => 'integer',
        'event_id' => 'integer'
    ];

    const UPDATED_AT = null;
    const CREATED_AT = 'viewed_at';

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public static function trackView(int $userId, int $eventId, string $ipAddress): void
    {
        $recentView = self::where('user_id', $userId)
            ->where('event_id', $eventId)
            ->where('viewed_at', '>=', now()->subHour())
            ->exists();

        if (!$recentView) {
            self::create([
                'user_id' => $userId,
                'event_id' => $eventId,
                'viewed_at' => now(),
                'ip_address' => $ipAddress
            ]);
        }
    }
}
