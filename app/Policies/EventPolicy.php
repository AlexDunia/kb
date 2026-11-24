<?php

namespace App\Policies;

use App\Models\Event;
use App\Models\User;

class EventPolicy
{
    public function update(User $user, Event $event): bool
    {
        return $event->created_by !== null && (int) $event->created_by === (int) $user->id;
    }
}
