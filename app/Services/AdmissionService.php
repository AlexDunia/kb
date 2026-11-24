<?php

namespace App\Services;

use App\Models\Attendee;
use App\Models\Event;
use App\Models\Ticket;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class AdmissionService
{
    public function __construct(private EventAccessService $access) {}

    private function credential(Event $event, string $token, bool $forExit = false): Ticket
    {
        $ticket = Ticket::where('event_id', $event->id)->where('token_hash', hash('sha256', $token))->with('orderItem.order')->firstOrFail();
        abort_unless($forExit || ($ticket->status === 'valid' && $ticket->orderItem->order->isFulfilled()), 422, 'This admission credential is no longer valid.');
        abort_unless($forExit || $event->event_type !== 'recurring', 422, 'Occurrence-specific admission has not been configured for this recurring event.');

        return $ticket;
    }

    private function summary(Ticket $ticket): array
    {
        return ['ticket_public_id' => $ticket->public_id, 'ticket_status' => $ticket->status, 'ticket_name' => $ticket->orderItem->ticket_name,
            'admit_count' => $ticket->admit_count, 'inside_count' => $ticket->attendees()->where('inside', true)->count(),
            'remaining_first_entries' => $ticket->attendees()->where('entries', 0)->count()];
    }

    public function lookup(Event $event, int $userId, array $input): array
    {
        $this->access->authorize($event, $userId, 'admission', $input['entrance_id']);
        abort_unless(DB::table('event_entrances')->where('id', $input['entrance_id'])->where('event_id', $event->id)->where('active', true)->exists(), 422, 'Choose an active entrance for this event.');

        $exit = ($input['direction'] ?? 'in') === 'out';
        abort_if(! $exit && in_array($event->lifecycle_state, ['cancelled', 'completed', 'archived'], true), 422, 'This event is not accepting entry.');

        return $this->summary($this->credential($event, $input['token'], $exit));
    }

    public function move(Event $event, int $userId, array $input): array
    {
        return DB::transaction(function () use ($event, $userId, $input) {
            $event = Event::whereKey($event->id)->sharedLock()->firstOrFail();
            $this->access->authorize($event, $userId, 'admission', $input['entrance_id']);
            $fingerprint = hash('sha256', json_encode([$userId, $input['entrance_id'], hash('sha256', $input['token']), $input['direction'], $input['count']], JSON_THROW_ON_ERROR));
            DB::table('admission_requests')->insertOrIgnore(['event_id' => $event->id, 'request_key' => $input['request_key'], 'fingerprint' => $fingerprint, 'created_at' => now(), 'updated_at' => now()]);
            $request = DB::table('admission_requests')->where('event_id', $event->id)->where('request_key', $input['request_key'])->lockForUpdate()->first();
            abort_unless(hash_equals($request->fingerprint, $fingerprint), 409, 'This scan request key belongs to a different operation.');
            if ($request->response) {
                return json_decode($request->response, true, flags: JSON_THROW_ON_ERROR);
            }
            $entrance = DB::table('event_entrances')->where('event_id', $event->id)->where('id', $input['entrance_id'])->sharedLock()->first();
            abort_unless($entrance && $entrance->active, 422, 'Entrance is inactive or belongs to another event.');
            // Ticket row is the serialization point for every entitlement seat.
            Ticket::where('event_id', $event->id)->where('token_hash', hash('sha256', $input['token']))->lockForUpdate()->firstOrFail();
            $ticket = $this->credential($event, $input['token'], $input['direction'] === 'out');
            $event->refresh();
            $reason = null;
            $opens = $entrance->opens_at ? Carbon::parse($entrance->opens_at) : $event->date?->copy()->subHours(2);
            $closes = $entrance->closes_at ? Carbon::parse($entrance->closes_at) : $event->ends_at;
            if ($input['direction'] === 'in' && ($event->status !== 'active' || in_array($event->lifecycle_state, ['cancelled', 'completed', 'archived'], true))) {
                $reason = 'event_inactive';
            }
            if ($input['direction'] === 'in' && (! $opens || ! $closes || now()->lt($opens) || now()->gt($closes))) {
                $reason = 'entrance_closed';
            }
            $eligible = Attendee::where('ticket_id', $ticket->id)->where('inside', $input['direction'] === 'out');
            if ($input['direction'] === 'in' && ! $event->allow_reentry) {
                $eligible->where('entries', 0);
            }
            $seats = $eligible->orderBy('seat_index')->lockForUpdate()->limit($input['count'])->get();
            if ($seats->count() !== $input['count']) {
                $reason ??= 'insufficient_admission_entitlement';
            }
            if (! $reason) {
                foreach ($seats as $seat) {
                    $seat->update(['inside' => $input['direction'] === 'in', 'entries' => $seat->entries + ($input['direction'] === 'in' ? 1 : 0)]);
                }
                DB::table('admission_movements')->insert(['event_id' => $event->id, 'ticket_id' => $ticket->id, 'entrance_id' => $entrance->id, 'staff_user_id' => $userId,
                    'admission_request_id' => $request->id, 'direction' => $input['direction'], 'count' => $input['count'], 'attendee_ids' => json_encode($seats->pluck('id')->all()), 'occurred_at' => now()]);
            }
            $response = ['outcome' => $reason ? 'denied' : 'recorded', 'reason' => $reason, 'count' => $reason ? 0 : $input['count'], 'direction' => $input['direction'], 'request_key' => $input['request_key'], ...$this->summary($ticket)];
            DB::table('admission_requests')->where('id', $request->id)->update(['response' => json_encode($response, JSON_THROW_ON_ERROR), 'updated_at' => now()]);

            return $response;
        }, 3);
    }
}
