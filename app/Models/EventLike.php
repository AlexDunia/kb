<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventLike extends Model
{
    protected $table = 'event_likes';

    protected $fillable = [
        'user_id',
        'event_id'
    ];

    protected $casts = [
        'user_id' => 'integer',
        'event_id' => 'integer',
        'created_at' => 'datetime'
    ];

    const UPDATED_AT = null;

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public static function toggleLike(int $userId, int $eventId): bool
    {
        $like = self::where('user_id', $userId)
            ->where('event_id', $eventId)
            ->first();

        if ($like) {
            $like->delete();
            return false;
        }

        self::create([
            'user_id' => $userId,
            'event_id' => $eventId
        ]);

        return true;
    }

    public static function isLiked(int $userId, int $eventId): bool
    {
        return self::where('user_id', $userId)
            ->where('event_id', $eventId)
            ->exists();
    }
}
