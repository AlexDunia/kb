<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Event;
use App\Models\EventAddress;
use App\Models\OrderItem;
use App\Models\Organizer;
use App\Models\Purchase;
use App\Services\Commerce\InventoryService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateEventService
{
    public function __construct(private EventEditorValidation $validation, private EventMediaService $images, private InventoryService $inventory) {}

    public function saveEvent(array $input, ?int $userId, ?int $existingEventId = null, bool $publish = false): Event
    {
        $createdFiles = [];
        $eventId = $existingEventId;
        try {
            return DB::transaction(function () use ($input, $userId, $existingEventId, $publish, &$createdFiles, &$eventId) {
                $event = $existingEventId ? Event::whereKey($existingEventId)->lockForUpdate()->firstOrFail() : new Event;
                if ($event->exists) {
                    abort_unless($userId !== null && (int) $event->created_by === $userId, 403);
                    abort_unless(in_array($event->lifecycle_state ?? 'open', ['open', 'sales_closed'], true), 409, 'This event can no longer be edited.');
                    abort_unless(in_array($event->status, ['draft', 'active'], true), 422, 'This event cannot be edited in its current state.');
                    abort_unless(isset($input['version']), 428, 'An event version is required. Reload the editor first.');
                    abort_unless(filter_var($input['version'], FILTER_VALIDATE_INT) !== false && (int) $input['version'] === (int) $event->lock_version, 409, 'This event changed. Reload it before saving again.');
                    if ($publish) {
                        abort_unless($event->status === 'draft', 422, 'Event is already published');
                    }
                    // Checkout and payment finalization lock these same rows in ID order.
                    // Acquire them before any non-locking reads establish a repeatable-read snapshot.
                    $event->setRelation('ticketTypes', $event->ticketTypes()->orderBy('id')->lockForUpdate()->get());
                    $before = $this->editorPayload($event);
                } else {
                    abort_unless($userId !== null, 401);
                    $before = [];
                }
                $this->validation->supplied($input);
                $payload = array_replace($before, $input);
                if (! array_key_exists('publicProfile', $input)) {
                    unset($payload['publicProfile']);
                }
                foreach (['startsAt', 'endsAt'] as $key) {
                    if (array_key_exists($key.'Local', $input) && ! array_key_exists($key, $input)) {
                        unset($payload[$key]);
                    }
                    if (array_key_exists($key, $input) && ! array_key_exists($key.'Local', $input)) {
                        unset($payload[$key.'Local']);
                    }
                    if (isset($input['timeZone']) && ! array_key_exists($key.'Local', $input) && ! array_key_exists($key, $input)) {
                        unset($payload[$key.'Local']);
                    }
                }
                $existingTickets = collect($before['tickets'] ?? [])->keyBy('id');
                if (isset($input['timeZone']) && ! array_key_exists('tickets', $input)) {
                    foreach ($payload['tickets'] ?? [] as $i => $ticket) {
                        unset($payload['tickets'][$i]['salesStartLocal'], $payload['tickets'][$i]['salesEndLocal']);
                    }
                }
                if (array_key_exists('tickets', $input) && is_array($input['tickets'])) {
                    $incoming = [];
                    foreach ($input['tickets'] as $i => $ticket) {
                        if (! is_array($ticket)) {
                            throw ValidationException::withMessages(["tickets.$i" => ['A ticket must be an object.']]);
                        }
                        if (isset($ticket['id'])) {
                            if (filter_var($ticket['id'], FILTER_VALIDATE_INT) === false || ! $existingTickets->has($ticket['id'])) {
                                throw ValidationException::withMessages(["tickets.$i.id" => ['This ticket does not belong to the editable event products.']]);
                            }
                            $base = $existingTickets->get($ticket['id']);
                            $patch = $ticket;
                            $ticket = array_replace($base, $ticket);
                            foreach (['salesStart', 'salesEnd'] as $key) {
                                if (array_key_exists($key.'Local', $patch) && ! array_key_exists($key, $patch)) {
                                    unset($ticket[$key]);
                                }
                                if (array_key_exists($key, $patch) && ! array_key_exists($key.'Local', $patch)) {
                                    unset($ticket[$key.'Local']);
                                }
                                if (isset($input['timeZone']) && ! array_key_exists($key, $patch) && ! array_key_exists($key.'Local', $patch)) {
                                    unset($ticket[$key.'Local']);
                                }
                            }
                            $incoming[] = $ticket;
                        } else {
                            $incoming[] = $ticket;
                        }
                    }
                    if (($input['ticketCollectionMode'] ?? 'merge') === 'merge') {
                        $supplied = array_filter(array_column($incoming, 'id'));
                        $incoming = array_merge($existingTickets->except($supplied)->values()->all(), $incoming);
                    }
                    $payload['tickets'] = $incoming;
                }
                if (array_key_exists('publicProfile', $input) && is_array($input['publicProfile'])) {
                    $payload['publicProfile'] = array_replace($before['publicProfile'] ?? [], $input['publicProfile']);
                }
                if (($payload['ticketMode'] ?? 'free') === 'free') {
                    $payload['tickets'] = [];
                }
                if ($event->exists && ($payload['ticketMode'] ?? 'free') !== $event->ticket_mode && array_sum($this->inventory->reservedQuantities($event->ticketTypes->pluck('id')->all())) > 0) {
                    throw ValidationException::withMessages(['ticketMode' => ['Ticket mode cannot change while sold or reserved commitments exist.']]);
                }
                if ($event->exists && ($payload['eventType'] ?? 'one_time') !== $event->event_type && OrderItem::where('event_id', $event->id)->exists()) {
                    throw ValidationException::withMessages(['eventType' => ['Recurrence semantics cannot change after registration or checkout history exists.']]);
                }
                foreach (['title', 'description', 'venue', 'city', 'organiser', 'tags'] as $field) {
                    if (isset($payload[$field])) {
                        $payload[$field] = $this->text($payload[$field]);
                    }
                }
                foreach ($payload['tickets'] ?? [] as $i => $ticket) {
                    foreach (['name', 'perks'] as $field) {
                        if (isset($ticket[$field])) {
                            $payload['tickets'][$i][$field] = $this->text($ticket[$field]);
                        }
                    }
                }
                $payload = $this->validation->validate($payload, $publish || $event->status === 'active');
                if ($event->booking_cutoff_at && ! empty($payload['endsAt']) && \Carbon\Carbon::parse($payload['endsAt'])->lt($event->booking_cutoff_at)) {
                    throw ValidationException::withMessages(['endsAt' => ['Event end must not precede the booking cutoff.']]);
                }
                $oldUrls = $event->exists ? array_merge($event->media->pluck('url')->all(), array_filter([$event->main_image, $event->banner_url]), $this->profileImageUrls($event)) : [];
                $category = trim($payload['category'] ?? '');
                $categoryId = $category === '' ? null : Category::whereRaw('LOWER(name) = ?', [mb_strtolower($category)])->value('id');
                $organizerName = $this->text($payload['organiser'] ?? '') ?: 'Unknown Organiser';
                $organizer = Organizer::firstOrCreate(['name' => $organizerName], ['email' => null]);
                // Never mutate shared historical addresses in place.
                $address = $event->address ?? null;
                $venue = $this->text($payload['venue'] ?? '');
                $city = $this->text($payload['city'] ?? '');
                if (! $address || $address->venue_name !== $venue || $address->city !== $city) {
                    $address = EventAddress::create([
                        'venue_name' => $venue, 'city' => $city, 'address_line1' => '', 'state' => '', 'postal_code' => '', 'country' => 'Nigeria',
                    ]);
                }
                $address->forceFill(['city_search' => mb_strtolower($city)])->save();
                $event->forceFill([
                    'title' => $this->text($payload['title'] ?? ''), 'description' => $this->text($payload['description'] ?? ''),
                    'category_id' => $categoryId, 'organizer_id' => $organizer->id, 'address_id' => $address->id,
                    'date' => $payload['startsAt'] ? Carbon::parse($payload['startsAt'])->utc() : null,
                    'ends_at' => $payload['endsAt'] ? Carbon::parse($payload['endsAt'])->utc() : null,
                    'event_timezone' => $payload['timeZone'], 'event_type' => $payload['eventType'], 'event_format' => $payload['format'],
                    'meeting_link' => $payload['meetingLink'] ?? '', 'organiser_website' => $payload['organiserWebsite'] ?? '',
                    'tags' => $this->text($payload['tags'] ?? ''), 'ticket_mode' => $payload['ticketMode'],
                    'attendee_fields' => json_encode($payload['attendeeFields'] ?? []), 'extra_details' => json_encode($payload['extraDetails'] ?? []),
                    'recurrence_data' => json_encode($payload['eventType'] === 'recurring' ? ($payload['recurrence'] ?? null) : null),
                    'created_by' => $event->exists ? $event->created_by : $userId,
                    'status' => $publish ? 'active' : ($event->exists ? $event->status : 'draft'),
                    'lock_version' => $event->exists ? (int) $event->lock_version + 1 : 1,
                    'price' => 0, 'total_tickets' => 0,
                ])->save();
                $eventId = (int) $event->id;
                $this->syncTicketTypes($event, $payload);
                $cover = $this->images->image($payload['coverImage'] ?? null, $eventId, 'coverImage', $createdFiles);
                $gallery = [];
                foreach ($payload['secondaryImages'] ?? [] as $i => $image) {
                    $gallery[] = $this->images->image($image, $eventId, "secondaryImages.$i", $createdFiles);
                }
                $gallery = array_values(array_diff(array_unique($gallery), [$cover]));
                $urls = array_merge($cover ? [$cover] : [], $gallery);
                foreach ($urls as $index => $url) {
                    $media = $event->media()->updateOrCreate(['url' => $url], [
                        'source_type' => $this->images->isUpload($url) ? 'upload' : 'external_url', 'is_cover' => $url === $cover, 'sort_order' => $index,
                    ]);
                    $event->media()->where('url', $url)->whereKeyNot($media->id)->delete();
                }
                $event->media()->whereNotIn('url', $urls)->delete();
                if (array_key_exists('publicProfile', $payload) && $payload['publicProfile'] !== null) {
                    $profile = $payload['publicProfile'];
                    if (! empty($profile['organiser_image'])) {
                        $this->images->image($profile['organiser_image'], $eventId, 'publicProfile.organiser_image', $createdFiles);
                    }
                    foreach (['moments' => 'url', 'guests' => 'image', 'crew' => 'image'] as $group => $key) {
                        foreach ($profile[$group] ?? [] as $i => $item) {
                            if (! empty($item[$key])) {
                                $this->images->image($item[$key], $eventId, "publicProfile.$group.$i.$key", $createdFiles);
                            }
                        }
                    }
                    $event->publicProfile()->updateOrCreate([], $profile);
                }
                $types = $event->ticketTypes()->get();
                $counts = $this->inventory->reservedQuantities($types->pluck('id')->all());
                $capacity = $payload['ticketMode'] === 'free' ? (int) ($payload['freeCapacity'] ?? 0) : $types->sum(fn ($type) => ($type->retired_at ? ($counts[$type->id] ?? 0) : $type->quantity) * $type->people_per_unit);
                if ($capacity > 2147483647) {
                    throw ValidationException::withMessages(['tickets' => ['Total admission capacity exceeds the supported limit.']]);
                }
                $prices = $types->whereNull('retired_at')->where('visible', true)->pluck('price');
                $event->forceFill(['main_image' => $cover, 'banner_url' => $gallery[0] ?? null, 'total_tickets' => $capacity, 'price' => $payload['ticketMode'] === 'paid' && $prices->isNotEmpty() ? $prices->min() : 0])->save();
                DB::afterCommit(function () use ($eventId, $oldUrls, $createdFiles) {
                    try {
                        $this->images->discardUnreferenced($eventId, $oldUrls);
                        $this->images->discardCreated($createdFiles);
                    } catch (\Throwable $cleanupError) {
                        report($cleanupError);
                    }
                });

                return $event->fresh();
            }, 3);
        } catch (\Throwable $e) {
            try {
                $this->images->discardCreated($createdFiles);
            } catch (\Throwable $cleanupError) {
                report($cleanupError);
            }
            throw $e;
        }
    }

    private function syncTicketTypes(Event $event, array $payload): void
    {
        $existing = $event->ticketTypes()->where('is_registration', false)->get()->keyBy('id');
        $registration = $event->ticketTypes()->where('is_registration', true)->first();
        if ($registration && $payload['ticketMode'] === 'free') {
            $committed = $this->inventory->reservedQuantities([$registration->id])[$registration->id] ?? 0;
            if ((int) ($payload['freeCapacity'] ?? 0) < $committed) {
                throw ValidationException::withMessages(['freeCapacity' => ['Capacity cannot be less than existing registrations.']]);
            }
            $registration->update(['quantity' => (int) ($payload['freeCapacity'] ?? 0)]);
        }
        $counts = $this->inventory->reservedQuantities($existing->keys()->all());
        $kept = [];
        foreach ($payload['tickets'] as $i => $ticket) {
            $type = isset($ticket['id']) ? $existing->get($ticket['id']) : $event->ticketTypes()->make();
            $history = $type->exists && (OrderItem::where('ticket_type_id', $type->id)->exists() || Purchase::where('ticket_type_id', $type->id)->exists());
            if ((int) ($ticket['units'] ?? 0) < ($counts[$type->id] ?? 0)) {
                throw ValidationException::withMessages(["tickets.$i.units" => ['Capacity cannot be less than sold and reserved units.']]);
            }
            if ($history && ($type->unit_type !== $ticket['unitType'] || (int) $type->people_per_unit !== (int) $ticket['peoplePerUnit'])) {
                throw ValidationException::withMessages(["tickets.$i.peoplePerUnit" => ['Admission semantics cannot change after commercial history exists.']]);
            }
            $type->forceFill([
                'name' => $this->text($ticket['name'] ?? ''), 'unit_type' => $ticket['unitType'], 'color' => $ticket['color'] ?? '#ec4899',
                'price' => $ticket['price'] ?? 0, 'quantity' => $ticket['units'] ?? 0, 'people_per_unit' => $ticket['peoplePerUnit'],
                'max_per_person' => $ticket['maxPerPerson'] ?? null, 'visible' => $ticket['visible'] ?? true, 'description' => $this->text($ticket['perks'] ?? ''),
                'sales_start_date' => $ticket['salesStart'] ? Carbon::parse($ticket['salesStart'])->utc() : null,
                'sales_end_date' => $ticket['salesEnd'] ? Carbon::parse($ticket['salesEnd'])->utc() : null,
            ])->save();
            $kept[] = $type->id;
        }
        foreach ($existing->except($kept) as $type) {
            // Keep products even for failed/expired purchases: historical FK references and snapshots are immutable.
            $type->forceFill(['visible' => false, 'retired_at' => $type->retired_at ?? now()])->save();
        }
    }

    private function profileImageUrls(Event $event): array
    {
        if (! $event->publicProfile) {
            return [];
        }
        $profile = $event->publicProfile;

        return array_filter(array_merge([$profile->organiser_image], array_column($profile->moments ?? [], 'url'), array_column($profile->guests ?? [], 'image'), array_column($profile->crew ?? [], 'image')));
    }

    private function text(mixed $value): string
    {
        return trim(strip_tags((string) $value));
    }

    public function editorPayload(Event $event): array
    {
        $event->loadMissing(['category', 'organizer', 'address', 'ticketTypes', 'media', 'publicProfile']);
        $counts = $this->inventory->commitments($event->ticketTypes->pluck('id')->all());
        $timeZone = $event->event_timezone ?: config('app.timezone', 'UTC');
        $toLocal = static function (mixed $value) use ($timeZone): ?string {
            if (empty($value)) {
                return null;
            }

            return Carbon::parse($value)->setTimezone($timeZone)->format('Y-m-d\TH:i:s');
        };

        return [
            'version' => (int) $event->lock_version,
            'city' => $event->address?->city,
            'publicProfile' => $event->publicProfile?->only(array_diff($event->publicProfile->getFillable(), ['event_id'])) ?? [],
            'title' => $event->title,
            'startsAt' => $event->date?->toIso8601String(),
            'endsAt' => $event->ends_at ? Carbon::parse($event->ends_at, 'UTC')->toIso8601String() : null,
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
            'tickets' => $event->ticketTypes->where('is_registration', false)->whereNull('retired_at')->map(fn ($ticket) => [
                'id' => $ticket->id,
                'inventory' => [
                    'configured_capacity_units' => (int) $ticket->quantity,
                    'sold_units' => $counts[$ticket->id]['sold_units'] ?? 0,
                    'reserved_units' => $counts[$ticket->id]['reserved_units'] ?? 0,
                    'available_units' => $ticket->visible ? max(0, (int) $ticket->quantity - ($counts[$ticket->id]['sold_units'] ?? 0) - ($counts[$ticket->id]['reserved_units'] ?? 0)) : 0,
                    'admission_capacity' => (int) $ticket->quantity * (int) $ticket->people_per_unit,
                ],
                'name' => $ticket->name,
                'unitType' => $ticket->unit_type ?: 'individual',
                'color' => $ticket->color ?: '#ec4899',
                'price' => (float) $ticket->price,
                'units' => (int) $ticket->quantity,
                'peoplePerUnit' => (int) ($ticket->people_per_unit ?: 1),
                'maxPerPerson' => $ticket->max_per_person,
                'visible' => (bool) $ticket->visible,
                'perks' => $ticket->description,
                'salesStart' => $ticket->sales_start_date?->toIso8601String(),
                'salesEnd' => $ticket->sales_end_date?->toIso8601String(),
                'salesStartLocal' => $toLocal($ticket->sales_start_date),
                'salesEndLocal' => $toLocal($ticket->sales_end_date),
            ])->values()->all(),
            'attendeeFields' => json_decode($event->attendee_fields ?? '[]', true) ?: [],
            'extraDetails' => json_decode($event->extra_details ?? '[]', true) ?: [],
        ];
    }
}
