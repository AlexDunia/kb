<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PublicEventResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $data = $this->resource->only([
            'id', 'title', 'description', 'category_id', 'date', 'ends_at',
            'event_timezone', 'event_type', 'event_format', 'price', 'total_tickets',
            'duration', 'featured', 'main_image', 'banner_image', 'banner_url',
            'status', 'ticket_mode', 'tags', 'organiser_website', 'created_by',
        ]);

        // Only these existing editor details are used as public programme content.
        $details = json_decode($this->resource->getAttribute('extra_details') ?? '[]', true);
        $data['extra_details'] = collect(is_array($details) ? $details : [])
            ->filter(fn ($detail) => is_array($detail) && in_array($detail['type'] ?? null, ['parking', 'dress', 'bring', 'agenda', 'speakers', 'food', 'transport', 'faq'], true))
            ->map(fn ($detail) => \Illuminate\Support\Arr::only($detail, ['type', 'label', 'value']))->values();

        foreach ([
            'category' => ['id', 'name'],
            'organizer' => ['id', 'name'],
            'address' => ['id', 'venue_name', 'address_line1', 'address_line2', 'city', 'state', 'postal_code', 'country'],
        ] as $relation => $fields) {
            if ($this->resource->relationLoaded($relation)) {
                $data[$relation] = $this->resource->getRelation($relation)?->only($fields);
            }
        }

        foreach ([
            'subCategories' => ['sub_categories', ['id', 'name', 'category_id']],
            'ticketTypes' => ['ticket_types', ['id', 'name', 'unit_type', 'color', 'price', 'quantity', 'people_per_unit', 'max_per_person', 'description', 'sales_start_date', 'sales_end_date', 'is_featured', 'visible', 'available_quantity', 'configured_capacity_units', 'sold_units', 'reserved_units', 'admission_capacity']],
            'media' => ['media', ['id', 'url', 'is_cover', 'sort_order']],
            'faqs' => ['faqs', ['id', 'question', 'answer']],
            'eventOptions' => ['event_options', ['id', 'name']],
        ] as $relation => [$key, $fields]) {
            if ($this->resource->relationLoaded($relation)) {
                $data[$key] = $this->resource->getRelation($relation)->map(fn ($item) => $item->only($fields))->values();
            }
        }

        if ($this->resource->relationLoaded('publicProfile')) {
            $data['public_profile'] = $this->publicProfile?->only([
                'summary', 'caption_title', 'caption_description', 'organiser_bio',
                'organiser_image', 'guests', 'schedule', 'moments', 'faqs', 'crew',
            ]);
        }

        if ($this->resource->relationLoaded('relatedEvents')) {
            $data['related_events'] = self::collection($this->resource->getRelation('relatedEvents'))->resolve($request);
        }

        return $data;
    }
}
