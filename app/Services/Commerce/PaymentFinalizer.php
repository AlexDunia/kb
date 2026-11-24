<?php

namespace App\Services\Commerce;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Ticket;
use App\Models\TicketType;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PaymentFinalizer
{
    public function __construct(private readonly InventoryService $inventory) {}

    public function finalizePaystack(string $reference, array $providerData): Order
    {
        $attempt = Payment::where('reference', $reference)->firstOrFail();
        $inventoryIds = OrderItem::where('order_id', $attempt->order_id)->orderBy('ticket_type_id')->pluck('ticket_type_id')->unique()->all();

        return DB::transaction(function () use ($attempt, $inventoryIds, $providerData) {
            // Existing-order operations always lock order, sorted inventory, then payments.
            $order = Order::whereKey($attempt->order_id)->lockForUpdate()->firstOrFail();
            $types = TicketType::whereIn('id', $inventoryIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $payment = Payment::whereKey($attempt->id)->lockForUpdate()->firstOrFail();
            if ($payment->status === Payment::STATUS_SUCCESS) {
                return $order->load('items.tickets', 'payments', 'allocations');
            }
            $status = strtolower((string) ($providerData['status'] ?? ''));
            // Validate identity on every response, including failures. Do not coerce
            // fractional/string-garbage amounts into a valid integer amount.
            $amount = $providerData['amount'] ?? null;
            $validAmount = (is_int($amount) || (is_string($amount) && ctype_digit($amount)))
                && (string) $amount === (string) $payment->amount_minor;
            $matches = ($providerData['reference'] ?? null) === $payment->reference
                && $validAmount && strtoupper((string) ($providerData['currency'] ?? '')) === strtoupper($payment->currency);
            if (! $matches) {
                return $this->review($payment, $order, $providerData, 'verification_mismatch');
            }
            if ($status !== 'success') {
                if (in_array($status, ['failed', 'abandoned', 'reversed'], true) && $payment->status !== Payment::STATUS_REVIEW) {
                    $payment->update(['status' => Payment::STATUS_FAILED, 'verified_at' => now(), 'gateway_response' => $status]);
                    $latestId = $order->payments()->orderByDesc('id')->value('id');
                    if ((int) $latestId === (int) $payment->id && $order->status === Order::STATUS_PENDING_PAYMENT) {
                        $order->update(['status' => Order::STATUS_PAYMENT_FAILED]);
                    }
                }

                return $order->fresh()->load('items.tickets', 'payments', 'allocations');
            }
            if ($payment->status === Payment::STATUS_REVIEW) {
                return $order->load('items.tickets', 'payments', 'allocations');
            }
            if (in_array($order->status, [Order::STATUS_CANCELLED, Order::STATUS_REFUNDED, Order::STATUS_PAYMENT_REVIEW], true)) {
                return $this->review($payment, $order, $providerData, 'terminal_order_charge');
            }
            if ($order->isPaid()) {
                return $this->review($payment, $order, $providerData, 'additional_successful_payment');
            }
            $providerId = isset($providerData['id']) ? (string) $providerData['id'] : null;
            if (! $providerId || strlen($providerId) > 40 || Payment::where('provider_transaction_id', $providerId)->where('id', '!=', $payment->id)->exists()) {
                return $this->review($payment, $order, $providerData, 'duplicate_or_invalid_provider_transaction');
            }
            $items = $order->items()->with('tickets')->orderBy('id')->get();
            // Always verify the entire commitment under inventory locks. Provider
            // payment time cannot resurrect a hold that has already been released.
            $committed = $this->inventory->reservedQuantities($inventoryIds, $order->id);
            foreach ($items as $item) {
                $type = $types->get($item->ticket_type_id);
                if (! $type || $item->quantity > $this->inventory->availableQuantity($type->quantity, $committed[$item->ticket_type_id] ?? 0)) {
                    return $this->review($payment, $order, $providerData, 'unfulfillable_charge');
                }
            }
            try {
                $paidAt = ! empty($providerData['paid_at']) ? Carbon::parse($providerData['paid_at']) : now();
            } catch (\Throwable) {
                return $this->review($payment, $order, $providerData, 'invalid_payment_timestamp');
            }
            $payment->update([
                'status' => Payment::STATUS_SUCCESS, 'provider_transaction_id' => $providerId,
                'channel' => Str::limit((string) ($providerData['channel'] ?? ''), 50, ''),
                'gateway_response' => 'Verified successful payment', 'paid_at' => $paidAt, 'verified_at' => now(),
            ]);
            $order->update(['status' => Order::STATUS_PAID, 'paid_at' => $paidAt]);
            foreach ($items as $item) {
                for ($index = $item->tickets->count(); $index < $item->quantity; $index++) {
                    $token = Str::random(48);
                    Ticket::create([
                        'public_id' => (string) Str::ulid(), 'order_item_id' => $item->id,
                        'event_id' => $item->event_id, 'ticket_type_id' => $item->ticket_type_id,
                        'token' => $token, 'token_hash' => hash('sha256', $token),
                        'admit_count' => max(1, $item->people_per_unit), 'status' => 'valid', 'issued_at' => now(),
                    ]);
                }
            }
            DB::table('commerce_deliveries')->insertOrIgnore([
                'order_id' => $order->id, 'deduplication_key' => 'purchase:'.$order->id,
                'kind' => 'purchase', 'next_attempt_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);

            // The durable outbox is drained by commerce:dispatch, even if a worker
            // or web process crashes immediately after this commit.
            return $order->fresh()->load('items.tickets', 'payments', 'allocations');
        }, 3);
    }

    private function review(Payment $payment, Order $order, array $data, string $reason): Order
    {
        DB::table('payment_resolutions')->insertOrIgnore([
            'payment_id' => $payment->id, 'reason' => $reason,
            'evidence' => json_encode(array_intersect_key($data, array_flip(['id', 'reference', 'amount', 'currency', 'status', 'paid_at'])), JSON_THROW_ON_ERROR),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $payment->update(['status' => Payment::STATUS_REVIEW, 'gateway_response' => $reason, 'verified_at' => now()]);
        if (! in_array($order->status, [Order::STATUS_PAID, Order::STATUS_CANCELLED, Order::STATUS_REFUNDED], true)) {
            $order->update(['status' => Order::STATUS_PAYMENT_REVIEW]);
        }

        return $order->fresh()->load('items.tickets', 'payments', 'allocations');
    }
}
