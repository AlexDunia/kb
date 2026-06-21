<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\EventAddress;
use App\Services\CreateEventService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CreateEventController extends Controller
{
    // TODO: add auth:sanctum middleware when auth is re-enabled
    public function store(Request $request, CreateEventService $service): JsonResponse
    {
        $request->validate($this->draftRules());
        $event = $service->saveEvent($request->all(), auth('sanctum')->id());

        return response()->json([
            'data' => $this->eventSummary($event),
            'message' => 'Draft saved',
        ], 201);
    }

    // TODO: add auth:sanctum middleware when auth is re-enabled
    public function update(Request $request, int $id, CreateEventService $service): JsonResponse
    {
        $event = Event::findOrFail($id);
        if ($event->status !== 'draft') {
            return response()->json(['message' => 'Cannot update a published event'], 422);
        }

        $request->validate($this->draftRules());
        $payload = array_replace($this->draftPayload($event), $request->all());
        $event = $service->saveEvent($payload, auth('sanctum')->id() ?? $event->created_by, $id);

        return response()->json([
            'data' => $this->eventSummary($event),
            'message' => 'Draft updated',
        ]);
    }

    // TODO: add auth:sanctum middleware when auth is re-enabled
    public function publish(int $id): JsonResponse
    {
        $event = Event::findOrFail($id);
        if ($event->status !== 'draft') {
            return response()->json(['message' => 'Event is already published'], 422);
        }

        $event->load(['ticketTypes', 'media']);
        $errors = [];

        if (empty(trim($event->title ?? ''))) {
            $errors['title'] = 'Event name is required';
        }
        if (empty($event->date)) {
            $errors['startsAt'] = 'Start date and time is required';
        }
        if (empty($event->category_id)) {
            $errors['category'] = 'Category is required';
        }
        if (empty($event->main_image) && $event->media->where('is_cover', true)->isEmpty()) {
            $errors['coverImage'] = 'A cover image is required';
        }

        if (in_array($event->event_format, ['in-person', 'hybrid'], true)) {
            $address = EventAddress::find($event->address_id);
            if (! $address || empty(trim($address->venue_name ?? ''))) {
                $errors['venue'] = 'Venue is required for in-person or hybrid events';
            }
        }

        if (in_array($event->event_format, ['online', 'hybrid'], true)
            && empty(trim($event->meeting_link ?? ''))) {
            $errors['meetingLink'] = 'Meeting link is required for online or hybrid events';
        }

        if ($event->ticket_mode === 'paid') {
            if ($event->ticketTypes->isEmpty()) {
                $errors['tickets'] = 'At least one ticket type is required for paid events';
            } else {
                foreach ($event->ticketTypes as $ticket) {
                    if (empty(trim($ticket->name ?? ''))) {
                        $errors['tickets'] = 'All ticket types must have a name';
                        break;
                    }
                    if ($ticket->price < 0) {
                        $errors['ticketPrice'] = 'Ticket prices cannot be negative';
                        break;
                    }
                }
            }
        }

        if ($event->event_type === 'recurring') {
            $recurrence = json_decode($event->recurrence_data, true) ?: [];
            if (empty($recurrence['frequency'])) {
                $errors['recurrence'] = 'Recurrence frequency is required for recurring events';
            }
            if (empty($recurrence['seriesEndType'])) {
                $errors['seriesEnd'] = 'A series end rule is required for recurring events';
            }
        }

        if ($errors !== []) {
            return response()->json([
                'message' => 'Event is not ready to publish',
                'errors' => $errors,
            ], 422);
        }

        $event->forceFill(['status' => 'active'])->save();

        return response()->json([
            'data' => $this->eventSummary($event),
            'message' => 'Event published successfully',
        ]);
    }

    private function draftPayload(Event $event): array
    {
        $event->loadMissing(['category', 'organizer', 'address', 'ticketTypes', 'media']);

        return [
            'title' => $event->title,
            'startsAtLocal' => $event->date?->format('Y-m-d\TH:i:s'),
            'endsAtLocal' => $event->ends_at
                ? \Carbon\Carbon::parse($event->ends_at)->format('Y-m-d\TH:i:s')
                : null,
            'timeZone' => $event->event_timezone,
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
            'secondaryImages' => $event->media
                ->where('is_cover', false)
                ->sortBy('sort_order')
                ->pluck('url')
                ->values()
                ->all(),
            'ticketMode' => $event->ticket_mode,
            'freeCapacity' => $event->ticket_mode === 'free' ? $event->total_tickets : null,
            'tickets' => $event->ticketTypes->map(fn ($ticket) => [
                'name' => $ticket->name,
                'price' => (float) $ticket->price,
                'units' => $ticket->quantity,
                'unitType' => 'individual',
                'peoplePerUnit' => 1,
                'perks' => $ticket->description,
                'salesStart' => $ticket->sales_start_date,
                'salesEnd' => $ticket->sales_end_date,
            ])->all(),
            'attendeeFields' => json_decode($event->attendee_fields ?? '[]', true) ?: [],
            'extraDetails' => json_decode($event->extra_details ?? '[]', true) ?: [],
        ];
    }

    private function draftRules(): array
    {
        return [
            'title' => 'nullable|string|max:200',
            'startsAt' => 'nullable|date',
            'endsAt' => 'nullable|date',
            'startsAtLocal' => 'nullable|string|max:30',
            'endsAtLocal' => 'nullable|string|max:30',
            'timeZone' => 'nullable|string|max:60',
            'eventType' => 'nullable|in:one_time,recurring',
            'format' => 'nullable|in:in-person,online,hybrid',
            'venue' => 'nullable|string|max:500',
            'meetingLink' => 'nullable|string|max:1000',
            'category' => 'nullable|string|max:100',
            'description' => 'nullable|string|max:20000',
            'organiser' => 'nullable|string|max:200',
            'organiserWebsite' => 'nullable|string|max:500',
            'tags' => 'nullable|string|max:500',
            'coverImage' => 'nullable|string|max:5000000',
            'secondaryImages' => 'nullable|array',
            'secondaryImages.*' => 'nullable|string|max:5000000',
            'ticketMode' => 'nullable|in:free,paid',
            'freeCapacity' => 'nullable|integer|min:0',
            'tickets' => 'nullable|array',
            'tickets.*.name' => 'nullable|string|max:200',
            'tickets.*.price' => 'nullable|numeric|min:0',
            'tickets.*.units' => 'nullable|integer|min:0',
            'tickets.*.unitType' => 'nullable|in:individual,table',
            'tickets.*.peoplePerUnit' => 'nullable|integer|min:1',
            'tickets.*.maxPerPerson' => 'nullable|integer|min:1',
            'tickets.*.salesStart' => 'nullable|date',
            'tickets.*.salesEnd' => 'nullable|date',
            'tickets.*.salesStartLocal' => 'nullable|string|max:30',
            'tickets.*.salesEndLocal' => 'nullable|string|max:30',
            'tickets.*.visible' => 'nullable|boolean',
            'tickets.*.color' => 'nullable|string|max:20',
            'tickets.*.perks' => 'nullable|string|max:1000',
            'attendeeFields' => 'nullable|array',
            'attendeeFields.*.id' => 'nullable|string|max:50',
            'attendeeFields.*.label' => 'nullable|string|max:200',
            'attendeeFields.*.enabled' => 'nullable|boolean',
            'attendeeFields.*.required' => 'nullable|boolean',
            'extraDetails' => 'nullable|array',
            'extraDetails.*.type' => 'nullable|string|max:50',
            'extraDetails.*.label' => 'nullable|string|max:200',
            'extraDetails.*.value' => 'nullable|string|max:5000',
            'recurrence' => 'nullable|array',
            'recurrence.frequency' => 'nullable|in:daily,weekly,monthly',
            'recurrence.weeklyDays' => 'nullable|array',
            'recurrence.weeklyDays.*' => 'nullable|integer|min:0|max:6',
            'recurrence.monthlyMode' => 'nullable|in:day_of_month,nth_weekday',
            'recurrence.dayOfMonth' => 'nullable|integer|min:1|max:31',
            'recurrence.nthWeekday' => 'nullable|array',
            'recurrence.nthWeekday.ordinal' => 'nullable|integer|min:1|max:5',
            'recurrence.nthWeekday.weekday' => 'nullable|integer|min:0|max:6',
            'recurrence.seriesEndType' => 'nullable|in:on_date,after_occurrences',
            'recurrence.seriesEndDate' => 'nullable|date',
            'recurrence.occurrenceCount' => 'nullable|integer|min:1',
        ];
    }

    private function eventSummary(Event $event): array
    {
        return ['id' => $event->id, 'status' => $event->status, 'title' => $event->title];
    }
}
