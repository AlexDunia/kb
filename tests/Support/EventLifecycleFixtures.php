<?php

namespace Tests\Support;

use App\Models\Category;
use App\Models\Event;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Services\CreateEventService;
use Illuminate\Support\Str;

trait EventLifecycleFixtures
{
    private function lifecycleEvent(User $owner, bool $active = false, bool $paid = true): Event
    {
        Category::firstOrCreate(['name' => 'Music']);
        $event = app(CreateEventService::class)->saveEvent([
            'title' => 'Lifecycle music', 'description' => 'A live music event', 'category' => 'Music',
            'startsAtLocal' => '2030-06-21T18:00:00', 'endsAtLocal' => '2030-06-21T22:00:00', 'timeZone' => 'Africa/Lagos',
            'venue' => 'Eko Hotel', 'city' => 'Lagos', 'format' => 'in-person', 'organiser' => 'Music Team',
            'coverImage' => 'https://example.com/cover.png', 'ticketMode' => $paid ? 'paid' : 'free', 'freeCapacity' => 0,
            'tickets' => $paid ? [['name' => 'Gold table', 'unitType' => 'table', 'price' => '100.00', 'units' => 10, 'peoplePerUnit' => 4, 'visible' => true]] : [],
        ], $owner->id);

        return $active ? app(CreateEventService::class)->saveEvent(['version' => 1], $owner->id, $event->id, true) : $event;
    }

    private function lifecycleOrder(Event $event, string $status = Order::STATUS_PENDING_PAYMENT, int $units = 3): Order
    {
        $type = $event->ticketTypes()->whereNull('retired_at')->firstOrFail();
        $order = Order::create([
            'public_id' => (string) Str::ulid(), 'checkout_token_hash' => hash('sha256', Str::uuid()),
            'cart_fingerprint' => hash('sha256', Str::uuid()), 'customer_first_name' => 'Ada', 'customer_last_name' => 'Buyer',
            'customer_email' => 'buyer@example.com', 'customer_phone' => '+2348000000000', 'currency' => 'NGN',
            'subtotal_minor' => $units * 10000, 'total_minor' => $units * 10000, 'discount_minor' => 0, 'status' => $status,
            'expires_at' => now()->addMinutes(20), 'paid_at' => $status === Order::STATUS_PAID ? now() : null,
        ]);
        OrderItem::create([
            'order_id' => $order->id, 'event_id' => $event->id, 'ticket_type_id' => $type->id, 'seller_user_id' => $event->created_by,
            'event_title' => $event->title, 'ticket_name' => $type->name, 'unit_type' => $type->unit_type,
            'people_per_unit' => $type->people_per_unit, 'unit_price_minor' => 10000, 'quantity' => $units,
            'line_total_minor' => $units * 10000, 'currency' => 'NGN',
        ]);

        return $order;
    }
}
