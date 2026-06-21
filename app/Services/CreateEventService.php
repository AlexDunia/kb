<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Event;
use App\Models\EventAddress;
use App\Models\Organizer;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CreateEventService
{
    public function resolveCategory(string $name): ?int
    {
        return Category::whereRaw('LOWER(name) = ?', [strtolower(trim($name))])->value('id');
    }

    public function resolveOrganizer(string $name): int
    {
        $id = Organizer::whereRaw('LOWER(name) = ?', [strtolower(trim($name))])->value('id');
        return $id !== null
            ? (int) $id
            : Organizer::create(['name' => $name, 'email' => ''])->id;
    }

    public function resolveAddress(string $venue): int
    {
        $id = EventAddress::where('venue_name', $venue)->value('id');
        return $id !== null
            ? (int) $id
            : EventAddress::create([
                'venue_name' => $venue,
                'address_line1' => '',
                'city' => '',
                'state' => '',
                'postal_code' => '',
                'country' => 'Nigeria',
            ])->id;
    }

    public function handleImage(string $imageData): string
    {
        if (preg_match('/^data:image\/(jpeg|jpg|png|webp|gif);base64,(.*)$/s', $imageData, $matches)) {
            $extension = in_array(strtolower($matches[1]), ['jpeg', 'jpg'], true)
                ? 'jpg'
                : strtolower($matches[1]);
            $decoded = base64_decode($matches[2], true);

            if ($decoded === false) {
                return '';
            }

            $path = 'events/images/'.Str::uuid().'.'.$extension;
            Storage::disk('public')->put($path, $decoded);
            return Storage::disk('public')->url($path);
        }

        return Str::startsWith($imageData, ['http://', 'https://']) ? $imageData : '';
    }

    public function computeTotalTickets(array $payload): int
    {
        if (($payload['ticketMode'] ?? 'free') === 'free') {
            return (int) ($payload['freeCapacity'] ?? 0);
        }

        return array_reduce($payload['tickets'] ?? [], function (int $total, array $ticket): int {
            $units = (int) ($ticket['units'] ?? 0);
            return $total + (($ticket['unitType'] ?? 'individual') === 'table'
                ? $units * (int) ($ticket['peoplePerUnit'] ?? 0)
                : $units);
        }, 0);
    }

    public function computeMinPrice(array $payload): float
    {
        $tickets = $payload['tickets'] ?? [];
        if (($payload['ticketMode'] ?? 'free') === 'free' || $tickets === []) {
            return 0.0;
        }

        return (float) min(array_map(
            fn (array $ticket) => (float) ($ticket['price'] ?? 0),
            $tickets,
        ));
    }

    public function syncTicketTypes(Event $event, array $payload): void
    {
        $event->ticketTypes()->delete();
        if (($payload['ticketMode'] ?? 'free') !== 'paid' || empty($payload['tickets'])) {
            return;
        }

        $timezone = $payload['timeZone'] ?? 'Africa/Lagos';
        foreach ($payload['tickets'] as $ticket) {
            $ticketType = $event->ticketTypes()->make();
            $ticketType->forceFill([
                'name' => $this->strip($ticket['name'] ?? ''),
                'price' => (float) ($ticket['price'] ?? 0),
                'quantity' => (int) ($ticket['units'] ?? 0),
                'description' => $this->strip($ticket['perks'] ?? ''),
                'sales_start_date' => $this->parseDateTime(
                    $ticket['salesStartLocal'] ?? null,
                    $ticket['salesStart'] ?? null,
                    $timezone,
                ),
                'sales_end_date' => $this->parseDateTime(
                    $ticket['salesEndLocal'] ?? null,
                    $ticket['salesEnd'] ?? null,
                    $timezone,
                ),
                'is_featured' => false,
            ])->save();
        }
    }

    public function buildRecurrenceData(array $payload): ?array
    {
        if (($payload['eventType'] ?? null) !== 'recurring' || empty($payload['recurrence'])) {
            return null;
        }

        return $payload['recurrence'];
    }

    public function saveEvent(array $payload, ?int $userId, ?int $existingEventId = null): Event
    {
        return DB::transaction(function () use ($payload, $userId, $existingEventId): Event {
            $categoryId = $this->resolveCategory($payload['category'] ?? '');
            $organizerId = $this->resolveOrganizer($this->strip($payload['organiser'] ?? 'Unknown Organiser'));
            $addressId = $this->resolveAddress($this->strip($payload['venue'] ?? ''));
            $mainImage = $this->handleImage($payload['coverImage'] ?? '');
            $bannerUrl = isset($payload['secondaryImages'][0])
                ? $this->handleImage($payload['secondaryImages'][0])
                : '';
            $timezone = $payload['timeZone'] ?? 'Africa/Lagos';

            $eventData = [
                'title' => $this->strip($payload['title'] ?? ''),
                'description' => $this->strip($payload['description'] ?? ''),
                'category_id' => $categoryId,
                'organizer_id' => $organizerId,
                'address_id' => $addressId,
                'date' => $this->parseDateTime($payload['startsAtLocal'] ?? null, $payload['startsAt'] ?? null, $timezone),
                'ends_at' => $this->parseDateTime($payload['endsAtLocal'] ?? null, $payload['endsAt'] ?? null, $timezone),
                'event_timezone' => $timezone,
                'event_type' => $payload['eventType'] ?? 'one_time',
                'price' => $this->computeMinPrice($payload),
                'total_tickets' => $this->computeTotalTickets($payload),
                'duration' => null,
                'featured' => false,
                'main_image' => $mainImage ?: null,
                'banner_image' => null,
                'banner_url' => $bannerUrl ?: null,
                'created_by' => $userId,
                'status' => 'draft',
                'event_format' => $payload['format'] ?? 'in-person',
                'meeting_link' => $this->strip($payload['meetingLink'] ?? ''),
                'organiser_website' => $this->strip($payload['organiserWebsite'] ?? ''),
                'tags' => $this->strip($payload['tags'] ?? ''),
                'ticket_mode' => $payload['ticketMode'] ?? 'free',
                'attendee_fields' => json_encode($payload['attendeeFields'] ?? []),
                'extra_details' => json_encode($payload['extraDetails'] ?? []),
                'recurrence_data' => json_encode($this->buildRecurrenceData($payload)),
            ];

            if ($existingEventId !== null) {
                $event = Event::findOrFail($existingEventId);
                if ($event->status !== 'draft') {
                    throw ValidationException::withMessages(['event' => 'Cannot update a published event']);
                }
            } else {
                $event = new Event();
            }

            // The existing model's fillable list is intentionally left untouched.
            $event->forceFill($eventData)->save();
            $this->syncTicketTypes($event, $payload);
            $event->media()->delete();

            if ($mainImage !== '') {
                $this->createMedia($event, $mainImage, true, 0);
            }

            foreach ($payload['secondaryImages'] ?? [] as $index => $image) {
                $url = $this->handleImage((string) $image);
                if ($url !== '') {
                    $this->createMedia($event, $url, false, $index + 1);
                }
            }

            return $event->fresh();
        });
    }

    private function createMedia(Event $event, string $url, bool $isCover, int $sortOrder): void
    {
        $event->media()->create([
            'is_cover' => $isCover,
            'sort_order' => $sortOrder,
            'url' => $url,
            'source_type' => Str::startsWith($url, ['http://', 'https://']) ? 'external_url' : 'upload',
        ]);
    }

    private function parseDateTime(mixed $local, mixed $fallback, string $timezone): ?Carbon
    {
        if (! empty($local)) {
            return Carbon::createFromFormat('Y-m-d\TH:i:s', (string) $local, $timezone);
        }

        return ! empty($fallback) ? Carbon::parse((string) $fallback) : null;
    }

    private function strip(mixed $value): string
    {
        $str = (string) ($value ?? '');
        $str = preg_replace('/<script[\s\S]*?>[\s\S]*?<\/script>/i', '', $str);
        $str = preg_replace('/[<>]/', '', $str);
        return trim($str);
    }
}
