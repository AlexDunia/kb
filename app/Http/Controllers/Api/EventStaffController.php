<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Services\EventAccessService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class EventStaffController extends Controller
{
    public function entrances(Request $request, Event $event, EventAccessService $access)
    {
        $access->authorize($event, $request->user()->id, 'owner');

        return response()->json(['data' => DB::table('event_entrances')->where('event_id', $event->id)->orderBy('id')->get()->map(fn ($row) => \App\Support\UtcDates::row($row))]);
    }

    public function saveEntrance(Request $request, Event $event, EventAccessService $access, ?int $entrance = null)
    {
        $access->authorize($event, $request->user()->id, 'owner');
        $input = $request->validate(['name' => 'required|string|max:100', 'active' => 'required|boolean', 'opens_at' => 'nullable|date', 'closes_at' => 'nullable|date']);

        return DB::transaction(function () use ($event, $entrance, $input) {
            Event::whereKey($event->id)->lockForUpdate()->firstOrFail();
            $row = $entrance ? DB::table('event_entrances')->where('event_id', $event->id)->where('id', $entrance)->lockForUpdate()->first() : null;
            if ($entrance) {
                abort_unless($row, 404);
            } else {
                abort_if(DB::table('event_entrances')->where('event_id', $event->id)->count() >= 50, 422, 'An event can have at most 50 entrances.');
            }
            $opens = empty($input['opens_at']) ? $event->date?->copy()->subHours(2) : \Illuminate\Support\Carbon::parse($input['opens_at']);
            $closes = empty($input['closes_at']) ? $event->ends_at : \Illuminate\Support\Carbon::parse($input['closes_at']);
            abort_if($opens && $closes && $closes->lte($opens), 422, 'Entrance closing time must be after opening time.');
            $data = [...$input, 'updated_at' => now()];
            foreach (['opens_at', 'closes_at'] as $key) {
                $data[$key] = empty($data[$key]) ? null : \Illuminate\Support\Carbon::parse($data[$key])->utc();
            }
            if ($entrance) {
                DB::table('event_entrances')->where('id', $entrance)->update($data);
            } else {
                $entrance = DB::table('event_entrances')->insertGetId(['event_id' => $event->id, ...$data, 'created_at' => now()]);
            }

            return response()->json(['data' => \App\Support\UtcDates::row(DB::table('event_entrances')->find($entrance))]);
        }, 3);
    }

    public function settings(Request $request, Event $event, EventAccessService $access)
    {
        $access->authorize($event, $request->user()->id, 'owner');
        $input = $request->validate(['allow_reentry' => 'required|boolean']);
        $event->forceFill($input)->save();

        return response()->json(['data' => $input]);
    }

    public function staff(Request $request, Event $event, EventAccessService $access)
    {
        $access->authorize($event, $request->user()->id, 'owner');

        $input = $request->validate(['page' => 'sometimes|integer|min:1', 'per_page' => 'sometimes|integer|min:1|max:100']);
        $members = DB::table('event_staff')->join('users', 'users.id', '=', 'event_staff.user_id')->where('event_staff.event_id', $event->id)
            ->select(['event_staff.*', 'users.name', 'users.email'])->orderBy('event_staff.id')->paginate($input['per_page'] ?? 30);

        return response()->json(['data' => array_map(fn ($row) => \App\Support\UtcDates::row($row), $members->items()), 'meta' => ['current_page' => $members->currentPage(), 'last_page' => $members->lastPage(), 'total' => $members->total()],
            'invitations' => DB::table('event_staff_invitations')->where('event_id', $event->id)->orderByDesc('id')->limit(100)->get(['id', 'email', 'role', 'entrance_id', 'expires_at', 'accepted_at', 'revoked_at', 'sent_at'])->map(fn ($row) => \App\Support\UtcDates::row($row))]);
    }

    public function invite(Request $request, Event $event, EventAccessService $access)
    {
        $access->authorize($event, $request->user()->id, 'owner');
        $input = $request->validate(['email' => 'required|email:rfc|max:255', 'role' => 'required|in:door,manager', 'entrance_id' => 'nullable|integer']);
        if (! empty($input['entrance_id'])) {
            abort_unless(DB::table('event_entrances')->where('event_id', $event->id)->where('id', $input['entrance_id'])->exists(), 422);
        }
        $token = Str::random(64);
        $id = DB::table('event_staff_invitations')->insertGetId(['event_id' => $event->id, 'invited_by' => $request->user()->id, 'email' => mb_strtolower(trim($input['email'])), 'role' => $input['role'],
            'entrance_id' => $input['entrance_id'] ?? null, 'token_hash' => hash('sha256', $token), 'encrypted_token' => Crypt::encryptString($token),
            'expires_at' => now()->addDays(7), 'next_delivery_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

        return response()->json(['data' => ['id' => $id, 'status' => 'invitation_queued']], 201);
    }

    public function accept(Request $request)
    {
        $input = $request->validate(['token' => 'required|string|size:64']);

        $eventId = DB::table('event_staff_invitations')->where('token_hash', hash('sha256', $input['token']))->value('event_id');
        abort_unless($eventId, 403);

        return DB::transaction(function () use ($input, $request, $eventId) {
            Event::whereKey($eventId)->lockForUpdate()->firstOrFail();
            $invitation = DB::table('event_staff_invitations')->where('token_hash', hash('sha256', $input['token']))->lockForUpdate()->first();
            abort_unless($invitation && ! $invitation->accepted_at && ! $invitation->revoked_at && now()->lt($invitation->expires_at)
                && hash_equals($invitation->email, mb_strtolower($request->user()->email)), 403, 'This invitation is invalid or belongs to another account.');
            DB::table('event_staff')->updateOrInsert(['event_id' => $invitation->event_id, 'user_id' => $request->user()->id], [
                'role' => $invitation->role, 'entrance_id' => $invitation->entrance_id, 'active' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('event_staff_invitations')->where('id', $invitation->id)->update(['accepted_at' => now(), 'encrypted_token' => null, 'updated_at' => now()]);

            return response()->json(['data' => ['event_id' => $invitation->event_id, 'role' => $invitation->role]]);
        }, 3);
    }

    public function revoke(Request $request, Event $event, int $member, EventAccessService $access)
    {
        $access->authorize($event, $request->user()->id, 'owner');
        DB::transaction(function () use ($event, $member) {
            Event::whereKey($event->id)->lockForUpdate()->firstOrFail();
            $staff = DB::table('event_staff')->where('event_id', $event->id)->where('id', $member)->lockForUpdate()->first();
            abort_unless($staff, 404);
            DB::table('event_staff')->where('id', $member)->update(['active' => false, 'updated_at' => now()]);
            $email = DB::table('users')->where('id', $staff->user_id)->value('email');
            DB::table('event_staff_invitations')->where('event_id', $event->id)->where('email', mb_strtolower($email))->whereNull('accepted_at')->update(['revoked_at' => now(), 'encrypted_token' => null, 'updated_at' => now()]);
        }, 3);

        return response()->json(['message' => 'Event access revoked.']);
    }

    public function revokeInvitation(Request $request, Event $event, int $invitation, EventAccessService $access)
    {
        $access->authorize($event, $request->user()->id, 'owner');
        abort_unless(DB::table('event_staff_invitations')->where('event_id', $event->id)->where('id', $invitation)->whereNull('accepted_at')->update(['revoked_at' => now(), 'encrypted_token' => null, 'updated_at' => now()]), 404);

        return response()->json(['message' => 'Invitation revoked.']);
    }
}
