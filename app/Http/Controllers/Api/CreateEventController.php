<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\EventAddress;
use App\Services\CreateEventService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CreateEventController extends Controller
{
    public function store(Request $request, CreateEventService $service): JsonResponse
    {
        $request->validate($this->draftRules());
        $event = $service->saveEvent($request->all(), auth('sanctum')->id());

        return response()->json([
            'data' => $this->eventSummary($event),
            'message' => 'Draft saved',
        ], 201);
    }

    public function showForEditing(int $id): JsonResponse
    {
        $event = Event::findOrFail($id);
        $this->authorizeOwner($event);

        if (! in_array($event->status, ['draft', 'active'], true)) {
            return response()->json([
                'message' => 'This event cannot be edited in its current state.',
            ], 422);
        }

        return response()->json([
            'data' => [
                'id' => $event->id,
                'status' => $event->status,
                'form' => $this->editorPayload($event),
            ],
        ]);
    }

    public function update(Request $request, int $id, CreateEventService $service): JsonResponse
    {
        $event = Event::findOrFail($id);
        $this->authorizeOwner($event);

        if (! in_array($event->status, ['draft', 'active'], true)) {
            return response()->json([
                'message' => 'This event cannot be edited in its current state.',
            ], 422);
        }

        $request->validate($this->draftRules());
        $payload = array_replace($this->editorPayload($event), $request->all());

        if ($event->status === 'active') {
            $errors = $this->publishedPayloadErrors($payload);
            if ($errors !== []) {
                return response()->json([
                    'message' => 'We could not save these changes.',
                    'errors' => $errors,
                ], 422);
            }
        }

        $event = $service->saveEvent($payload, auth('sanctum')->id(), $id);

        return response()->json([
            'data' => $this->eventSummary($event),
            'message' => $event->status === 'active' ? 'Event updated' : 'Draft updated',
        ]);
    }

    public function publish(int $id): JsonResponse
    {
        $event = Event::findOrFail($id);
        $this->authorizeOwner($event);

        if ($event->status !== 'draft') {
            return response()->json(['message' => 'Event is already published'], 422);
        }

        $event->load(['ticketTypes', 'media']);
        $errors = [];
        if (empty(trim($event->title ?? ''))) $errors['title'] = 'Event name is required';
        if (empty($event->date)) $errors['startsAt'] = 'Start date and time is required';
        if (empty($event->category_id)) $errors['category'] = 'Category is required';
        if (empty($event->main_image) && $event->media->where('is_cover', true)->isEmpty()) $errors['coverImage'] = 'A cover image is required';
        if (in_array($event->event_format, ['in-person', 'hybrid'], true)) {
            $address = EventAddress::find($event->address_id);
            if (! $address || empty(trim($address->venue_name ?? ''))) $errors['venue'] = 'Venue is required for in-person or hybrid events';
        }
        if (in_array($event->event_format, ['online', 'hybrid'], true) && empty(trim($event->meeting_link ?? ''))) $errors['meetingLink'] = 'Meeting link is required for online or hybrid events';
        if ($event->ticket_mode === 'paid') {
            if ($event->ticketTypes->isEmpty()) $errors['tickets'] = 'At least one ticket type is required for paid events';
            else foreach ($event->ticketTypes as $ticket) {
                if (empty(trim($ticket->name ?? ''))) { $errors['tickets'] = 'All ticket types must have a name'; break; }
                if ($ticket->price < 0) { $errors['ticketPrice'] = 'Ticket prices cannot be negative'; break; }
            }
        }
        if ($event->event_type === 'recurring') {
            $recurrence = json_decode($event->recurrence_data, true) ?: [];
            if (empty($recurrence['frequency'])) $errors['recurrence'] = 'Recurrence frequency is required for recurring events';
            if (empty($recurrence['seriesEndType'])) $errors['seriesEnd'] = 'A series end rule is required for recurring events';
        }
        if ($errors !== []) return response()->json(['message' => 'Event is not ready to publish', 'errors' => $errors], 422);

        $event->forceFill(['status' => 'active'])->save();
        return response()->json(['data' => $this->eventSummary($event), 'message' => 'Event published successfully']);
    }

    private function authorizeOwner(Event $event): void
    {
        $userId = auth('sanctum')->id();
        abort_unless(
            $userId !== null && $event->created_by !== null && (int) $event->created_by === (int) $userId,
            403,
            'You do not have permission to edit this event.'
        );
    }

    private function editorPayload(Event $event): array
    {
        $event->loadMissing(['category', 'organizer', 'address', 'ticketTypes', 'media']);
        $timeZone = $event->event_timezone ?: config('app.timezone', 'UTC');
        $toLocal = static function (mixed $value) use ($timeZone): ?string {
            if (empty($value)) return null;
            return Carbon::parse($value)->setTimezone($timeZone)->format('Y-m-d\TH:i:s');
        };

        return [
            'title' => $event->title,
            'startsAtLocal' => $toLocal($event->date),
            'endsAtLocal' => $toLocal($event->ends_at),
            'timeZone' => $timeZone,
            'eventType' => $event->event_type,
            'recurrence' => json_decode($event->recurrence_data ?? 'null', true),
            'format' => $event->event_format,
            'venue' => $event->address?->venue_name,
            'meetingLink' => $event->meeting_link,
            'category' => $event->category?->name,
            'description' => $event->description,
            'organiser' => $event->organizer?->name,
            'organiserWebsite' => $event->organiser_website,
            'tags' => $event->tags,
            'coverImage' => $event->main_image,
            'secondaryImages' => $event->media->where('is_cover', false)->sortBy('sort_order')->pluck('url')->values()->all(),
            'ticketMode' => $event->ticket_mode,
            'freeCapacity' => $event->ticket_mode === 'free' ? $event->total_tickets : null,
            'tickets' => $event->ticketTypes->map(fn ($ticket) => [
                'id' => $ticket->id,
                'name' => $ticket->name,
                'unitType' => $ticket->unit_type ?: 'individual',
                'color' => $ticket->color ?: '#ec4899',
                'price' => (float) $ticket->price,
                'units' => (int) $ticket->quantity,
                'peoplePerUnit' => (int) ($ticket->people_per_unit ?: 1),
                'maxPerPerson' => $ticket->max_per_person,
                'visible' => (bool) $ticket->visible,
                'perks' => $ticket->description,
                'salesStartLocal' => $toLocal($ticket->sales_start_date),
                'salesEndLocal' => $toLocal($ticket->sales_end_date),
            ])->values()->all(),
            'attendeeFields' => json_decode($event->attendee_fields ?? '[]', true) ?: [],
            'extraDetails' => json_decode($event->extra_details ?? '[]', true) ?: [],
        ];
    }

    private function publishedPayloadErrors(array $payload): array
    {
        $errors = [];
        if (empty(trim((string) ($payload['title'] ?? '')))) $errors['title'] = 'Event name is required';
        if (empty($payload['startsAtLocal'] ?? $payload['startsAt'] ?? null)) $errors['startsAt'] = 'Start date and time is required';
        if (empty($payload['endsAtLocal'] ?? $payload['endsAt'] ?? null)) $errors['endsAt'] = 'End date and time is required';
        if (empty(trim((string) ($payload['category'] ?? '')))) $errors['category'] = 'Category is required';
        if (empty(trim((string) ($payload['coverImage'] ?? '')))) $errors['coverImage'] = 'A cover image is required';
        $format = $payload['format'] ?? 'in-person';
        if (in_array($format, ['in-person', 'hybrid'], true) && empty(trim((string) ($payload['venue'] ?? '')))) $errors['venue'] = 'Venue is required for in-person or hybrid events';
        if (in_array($format, ['online', 'hybrid'], true) && empty(trim((string) ($payload['meetingLink'] ?? '')))) $errors['meetingLink'] = 'Meeting link is required for online or hybrid events';
        if (($payload['ticketMode'] ?? 'free') === 'paid') {
            $tickets = $payload['tickets'] ?? [];
            if ($tickets === []) $errors['tickets'] = 'At least one ticket type is required for paid events';
            else foreach ($tickets as $ticket) {
                if (empty(trim((string) ($ticket['name'] ?? '')))) { $errors['tickets'] = 'All ticket types must have a name'; break; }
                if ((float) ($ticket['price'] ?? 0) < 0) { $errors['ticketPrice'] = 'Ticket prices cannot be negative'; break; }
            }
        }
        if (($payload['eventType'] ?? null) === 'recurring') {
            $recurrence = $payload['recurrence'] ?? [];
            if (empty($recurrence['frequency'] ?? null)) $errors['recurrence'] = 'Recurrence frequency is required for recurring events';
            if (empty($recurrence['seriesEndType'] ?? null)) $errors['seriesEnd'] = 'A series end rule is required for recurring events';
        }
        $timeZone = $payload['timeZone'] ?? 'Africa/Lagos';
        $startsLocal = $payload['startsAtLocal'] ?? null;
        $endsLocal = $payload['endsAtLocal'] ?? null;
        if ($startsLocal && $endsLocal) {
            try {
                $starts = Carbon::createFromFormat('Y-m-d\TH:i:s', (string) $startsLocal, $timeZone);
                $ends = Carbon::createFromFormat('Y-m-d\TH:i:s', (string) $endsLocal, $timeZone);
                if ($ends->lessThanOrEqualTo($starts)) $errors['endsAt'] = 'End time must be after the start time';
            } catch (\Throwable) { $errors['startsAt'] = 'The event date and time are invalid'; }
        }
        return $errors;
    }

    private function draftRules(): array
    {
        return [
            'title' => 'nullable|string|max:200', 'startsAt' => 'nullable|date', 'endsAt' => 'nullable|date',
            'startsAtLocal' => 'nullable|string|max:30', 'endsAtLocal' => 'nullable|string|max:30', 'timeZone' => 'nullable|string|max:60',
            'eventType' => 'nullable|in:one_time,recurring', 'format' => 'nullable|in:in-person,online,hybrid', 'venue' => 'nullable|string|max:500',
            'meetingLink' => 'nullable|string|max:1000', 'category' => 'nullable|string|max:100', 'description' => 'nullable|string|max:20000',
            'organiser' => 'nullable|string|max:200', 'organiserWebsite' => 'nullable|string|max:500', 'tags' => 'nullable|string|max:500',
            'coverImage' => 'nullable|string|max:5000000', 'secondaryImages' => 'nullable|array', 'secondaryImages.*' => 'nullable|string|max:5000000',
            'ticketMode' => 'nullable|in:free,paid', 'freeCapacity' => 'nullable|integer|min:0', 'tickets' => 'nullable|array',
            'tickets.*.id' => 'nullable|integer|min:1', 'tickets.*.name' => 'nullable|string|max:200', 'tickets.*.unitType' => 'nullable|in:individual,table',
            'tickets.*.color' => 'nullable|string|max:20', 'tickets.*.price' => 'nullable|numeric|min:0', 'tickets.*.units' => 'nullable|integer|min:0',
            'tickets.*.peoplePerUnit' => 'nullable|integer|min:1', 'tickets.*.maxPerPerson' => 'nullable|integer|min:1', 'tickets.*.visible' => 'nullable|boolean',
            'tickets.*.perks' => 'nullable|string|max:1000', 'tickets.*.salesStart' => 'nullable|date', 'tickets.*.salesEnd' => 'nullable|date',
            'tickets.*.salesStartLocal' => 'nullable|string|max:30', 'tickets.*.salesEndLocal' => 'nullable|string|max:30',
            'attendeeFields' => 'nullable|array', 'attendeeFields.*.id' => 'nullable|string|max:50', 'attendeeFields.*.label' => 'nullable|string|max:200',
            'attendeeFields.*.enabled' => 'nullable|boolean', 'attendeeFields.*.required' => 'nullable|boolean',
            'extraDetails' => 'nullable|array', 'extraDetails.*.type' => 'nullable|string|max:50', 'extraDetails.*.label' => 'nullable|string|max:200',
            'extraDetails.*.value' => 'nullable|string|max:5000', 'recurrence' => 'nullable|array', 'recurrence.frequency' => 'nullable|in:daily,weekly,monthly',
            'recurrence.weeklyDays' => 'nullable|array', 'recurrence.weeklyDays.*' => 'nullable|integer|min:0|max:6',
            'recurrence.monthlyMode' => 'nullable|in:day_of_month,nth_weekday', 'recurrence.dayOfMonth' => 'nullable|integer|min:1|max:31',
            'recurrence.nthWeekday' => 'nullable|array', 'recurrence.nthWeekday.ordinal' => 'nullable|integer|min:1|max:5',
            'recurrence.nthWeekday.weekday' => 'nullable|integer|min:0|max:6', 'recurrence.seriesEndType' => 'nullable|in:on_date,after_occurrences',
            'recurrence.seriesEndDate' => 'nullable|date', 'recurrence.occurrenceCount' => 'nullable|integer|min:1',
        ];
    }

    private function eventSummary(Event $event): array
    {
        return ['id' => $event->id, 'status' => $event->status, 'title' => $event->title];
    }
}