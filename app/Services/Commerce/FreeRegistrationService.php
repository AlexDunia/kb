<?php

namespace App\Services\Commerce;

use App\Models\Event;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Ticket;
use App\Models\TicketType;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class FreeRegistrationService
{
    public function register(int $eventId, array $payload, ?int $userId): Order
    {
        $payload = Validator::make($payload, [
            'checkout_token' => 'required|uuid', 'quantity' => 'required|integer|min:1|max:20',
            'customer' => 'required|array:first_name,last_name,email,phone', 'customer.first_name' => 'required|string|max:100',
            'customer.last_name' => 'required|string|max:100', 'customer.email' => 'required|email:rfc|max:255', 'customer.phone' => 'nullable|string|max:50',
            'attendees' => 'sometimes|array|max:20', 'attendees.*' => 'array:unit_index,seat_index,answers',
            'attendees.*.unit_index' => 'required|integer|min:0|max:19', 'attendees.*.seat_index' => 'required|integer|in:0', 'attendees.*.answers' => 'required|array|max:30',
        ])->validate();
        $hash = hash('sha256', $payload['checkout_token']);
        $fingerprint = hash('sha256', json_encode(['event_id' => $eventId, 'quantity' => $payload['quantity'], 'customer' => $payload['customer'], 'attendees' => $payload['attendees'] ?? []], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($eventId, $payload, $userId, $hash, $fingerprint) {
            $event = Event::whereKey($eventId)->lockForUpdate()->firstOrFail();
            $existing = Order::where('checkout_token_hash', $hash)->lockForUpdate()->first();
            if ($existing) {
                abort_unless(hash_equals($existing->cart_fingerprint, $fingerprint), 422, 'This token belongs to a different registration.');

                return $existing;
            }
            abort_unless($event->bookingsOpen() && $event->ticket_mode === 'free' && $event->event_type !== 'recurring' && $event->ends_at?->isFuture(), 422, 'This event is not available for registration.');
            $type = TicketType::where('event_id', $event->id)->where('is_registration', true)->lockForUpdate()->first();
            if (! $type) {
                $type = $event->ticketTypes()->make(['name' => 'Free registration', 'unit_type' => 'individual', 'price' => 0, 'quantity' => $event->total_tickets, 'people_per_unit' => 1, 'visible' => false]);
                $type->forceFill(['is_registration' => true])->save();
            }
            $committed = app(InventoryService::class)->reservedQuantities([$type->id])[$type->id] ?? 0;
            abort_unless($payload['quantity'] <= max(0, $type->quantity - $committed), 422, 'Registration capacity is unavailable.');
            $order = Order::create([
                'public_id' => (string) Str::ulid(), 'checkout_token_hash' => $hash, 'cart_fingerprint' => $fingerprint, 'user_id' => $userId,
                'customer_first_name' => trim($payload['customer']['first_name']), 'customer_last_name' => trim($payload['customer']['last_name']),
                'customer_email' => mb_strtolower(trim($payload['customer']['email'])), 'customer_phone' => $payload['customer']['phone'] ?? '',
                'currency' => config('commerce.currency', 'NGN'), 'subtotal_minor' => 0, 'discount_minor' => 0, 'total_minor' => 0, 'status' => 'registered',
            ]);
            $item = OrderItem::create(['order_id' => $order->id, 'event_id' => $event->id, 'ticket_type_id' => $type->id, 'seller_user_id' => $event->created_by,
                'event_title' => $event->title, 'ticket_name' => $type->name, 'unit_type' => 'individual', 'people_per_unit' => 1, 'unit_price_minor' => 0,
                'quantity' => $payload['quantity'], 'line_total_minor' => 0, 'currency' => $order->currency]);
            $registration = app(RegistrationService::class);
            $registration->attach($order, array_map(fn ($attendee) => ['ticket_type_id' => $type->id, ...$attendee], $payload['attendees'] ?? []));
            for ($unit = 0; $unit < $item->quantity; $unit++) {
                $token = Str::random(48);
                $ticket = Ticket::create(['unit_index' => $unit, 'public_id' => (string) Str::ulid(), 'order_item_id' => $item->id, 'event_id' => $event->id, 'ticket_type_id' => $type->id,
                    'token' => $token, 'token_hash' => hash('sha256', $token), 'admit_count' => 1, 'status' => 'valid', 'issued_at' => now()]);
                $registration->link($ticket, $unit);
            }
            DB::table('commerce_deliveries')->insert(['order_id' => $order->id, 'deduplication_key' => 'purchase:'.$order->id, 'kind' => 'purchase', 'next_attempt_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

            return $order;
        }, 3);
    }
}
