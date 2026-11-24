<?php

namespace App\Services;

use App\Models\Event;
use Illuminate\Support\Facades\DB;

class EventAccessService
{
    public function authorize(Event $event, int $userId, string $ability, ?int $entranceId = null): string
    {
        if ((int) $event->created_by === $userId) {
            return 'owner';
        }
        $query = DB::table('event_staff')->where('event_id', $event->id)->where('user_id', $userId)->where('active', true);
        if (DB::transactionLevel() > 0) {
            $query->sharedLock();
        }
        $member = $query->first();
        abort_unless($member && ($ability === 'admission' || $member->role === 'manager') && $ability !== 'owner', 403);
        if ($entranceId !== null && $member->entrance_id !== null) {
            abort_unless((int) $member->entrance_id === $entranceId, 403);
        }

        return $member->role;
    }

    public function entranceScope(Event $event, int $userId): ?int
    {
        if ((int) $event->created_by === $userId) {
            return null;
        }

        return DB::table('event_staff')->where('event_id', $event->id)->where('user_id', $userId)->where('active', true)->value('entrance_id');
    }
}
