<?php

namespace App\Services\Commerce;

use App\Models\Order;
use App\Models\Payment;
use App\Models\Ticket;
use App\Models\TicketType;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PaymentFinalizer
{
    public function __construct(
        private readonly InventoryService $inventory
    ) {}

    public function finalizePaystack(
        string $reference,
        array $providerData
    ): Order {
        return DB::transaction(function () use (
            $reference,
            $providerData
        ) {
            $payment = Payment::query()
                ->where('reference', $reference)
                ->lockForUpdate()
                ->firstOrFail();

            $order = Order::query()
                ->whereKey($payment->order_id)
                ->lockForUpdate()
                ->firstOrFail();

            if (
                $payment->status === Payment::STATUS_SUCCESS
                && $order->isPaid()
            ) {
                return $order->load(
                    'items.tickets',
                    'payments',
                    'allocations'
                );
            }

            $providerStatus = strtolower(
                (string) ($providerData['status'] ?? '')
            );

            if ($providerStatus !== 'success') {
                if (
                    in_array(
                        $providerStatus,
                        ['failed', 'abandoned', 'reversed'],
                        true
                    )
                ) {
                    $payment->update([
                        'status' => Payment::STATUS_FAILED,
                        'provider_transaction_id' =>
                            isset($providerData['id'])
                                ? (string) $providerData['id']
                                : null,
                        'channel' =>
                            $providerData['channel'] ?? null,
                        'gateway_response' =>
                            $providerData['gateway_response']
                            ?? ucfirst($providerStatus),
                        'paid_at' => $providerData['paid_at'] ?? null,
                        'verified_at' => now(),
                    ]);

                    if (!$order->isPaid()) {
                        $order->update([
                            'status' =>
                                Order::STATUS_PAYMENT_FAILED,
                        ]);
                    }
                }

                return $order->fresh()->load(
                    'items.tickets',
                    'payments',
                    'allocations'
                );
            }

            $referenceMatches =
                (string) ($providerData['reference'] ?? '')
                === $payment->reference;

            $amountMatches =
                (int) ($providerData['amount'] ?? -1)
                === (int) $payment->amount_minor;

            $currencyMatches =
                strtoupper(
                    (string) ($providerData['currency'] ?? '')
                )
                === strtoupper((string) $payment->currency);

            if (
                !$referenceMatches
                || !$amountMatches
                || !$currencyMatches
            ) {
                $payment->update([
                    'status' => Payment::STATUS_REVIEW,
                    'provider_transaction_id' =>
                        isset($providerData['id'])
                            ? (string) $providerData['id']
                            : null,
                    'channel' => $providerData['channel'] ?? null,
                    'gateway_response' =>
                        $providerData['gateway_response']
                        ?? 'Verification mismatch',
                    'paid_at' =>
                        $providerData['paid_at'] ?? null,
                    'verified_at' => now(),
                ]);

                if (!$order->isPaid()) {
                    $order->update([
                        'status' =>
                            Order::STATUS_PAYMENT_REVIEW,
                    ]);
                }

                return $order->fresh()->load(
                    'items.tickets',
                    'payments',
                    'allocations'
                );
            }

            /*
             * A different successful payment for an order that is
             * already paid must never issue another set of tickets.
             * Keep the original order paid and flag only this extra
             * payment for review.
             */
            if ($order->isPaid()) {
                $payment->update([
                    'status' => Payment::STATUS_REVIEW,
                    'provider_transaction_id' =>
                        isset($providerData['id'])
                            ? (string) $providerData['id']
                            : null,
                    'channel' => $providerData['channel'] ?? null,
                    'gateway_response' =>
                        $providerData['gateway_response']
                        ?? 'Additional successful payment',
                    'paid_at' =>
                        $providerData['paid_at'] ?? now(),
                    'verified_at' => now(),
                ]);

                return $order->fresh()->load(
                    'items.tickets',
                    'payments',
                    'allocations'
                );
            }

            $items = $order->items()
                ->with('tickets')
                ->orderBy('id')
                ->get();

            $ticketTypeIds = $items
                ->pluck('ticket_type_id')
                ->filter()
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->sort()
                ->values()
                ->all();

            /*
             * Lock every affected ticket type in a deterministic
             * order before an inventory-sensitive finalization.
             * This serializes competing late-payment finalizations.
             */
            $ticketTypes = TicketType::query()
                ->whereIn('id', $ticketTypeIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $paidAt = isset($providerData['paid_at'])
                && $providerData['paid_at']
                ? Carbon::parse($providerData['paid_at'])
                : now();

            $paidBeforeOrAtExpiry =
                !$order->expires_at
                || $paidAt->lte($order->expires_at);

            /*
             * If Paystack says the customer actually paid before
             * our reservation expired, honor that payment even if
             * the webhook/browser verification reached us later.
             *
             * If the customer paid after expiry, the reservation no
             * longer protects inventory. Re-check all lines while
             * the ticket types are locked. If any line can no longer
             * be fulfilled, put the payment/order into review and
             * issue no tickets.
             */
            if (!$paidBeforeOrAtExpiry) {
                $reserved = $this->inventory
                    ->reservedQuantities(
                        $ticketTypeIds,
                        $order->id
                    );

                foreach ($items as $item) {
                    $ticketType = $ticketTypes->get(
                        (int) $item->ticket_type_id
                    );

                    if (!$ticketType) {
                        $this->moveToReview(
                            $payment,
                            $order,
                            $providerData,
                            $paidAt,
                            'Ticket type no longer exists'
                        );

                        return $order->fresh()->load(
                            'items.tickets',
                            'payments',
                            'allocations'
                        );
                    }

                    $available = $this->inventory
                        ->availableQuantity(
                            (int) $ticketType->quantity,
                            (int) (
                                $reserved[
                                    (int) $ticketType->id
                                ] ?? 0
                            )
                        );

                    if ((int) $item->quantity > $available) {
                        $this->moveToReview(
                            $payment,
                            $order,
                            $providerData,
                            $paidAt,
                            'Payment arrived after reservation expiry and inventory is no longer available'
                        );

                        return $order->fresh()->load(
                            'items.tickets',
                            'payments',
                            'allocations'
                        );
                    }
                }
            }

            $payment->update([
                'status' => Payment::STATUS_SUCCESS,
                'provider_transaction_id' =>
                    isset($providerData['id'])
                        ? (string) $providerData['id']
                        : null,
                'channel' => $providerData['channel'] ?? null,
                'gateway_response' =>
                    $providerData['gateway_response']
                    ?? 'Success',
                'paid_at' => $paidAt,
                'verified_at' => now(),
            ]);

            $order->update([
                'status' => Order::STATUS_PAID,
                'paid_at' => $paidAt,
            ]);

            foreach ($items as $item) {
                $alreadyIssued = $item->tickets->count();

                for (
                    $index = $alreadyIssued;
                    $index < (int) $item->quantity;
                    $index++
                ) {
                    $rawToken = Str::random(48);

                    Ticket::create([
                        'public_id' => (string) Str::ulid(),
                        'order_item_id' => $item->id,
                        'event_id' => $item->event_id,
                        'ticket_type_id' =>
                            $item->ticket_type_id,
                        'token' => $rawToken,
                        'token_hash' =>
                            hash('sha256', $rawToken),
                        'admit_count' => max(
                            1,
                            (int) $item->people_per_unit
                        ),
                        'status' => 'valid',
                        'issued_at' => now(),
                    ]);
                }
            }

            return $order->fresh()->load(
                'items.tickets',
                'payments',
                'allocations'
            );
        }, 3);
    }

    private function moveToReview(
        Payment $payment,
        Order $order,
        array $providerData,
        Carbon $paidAt,
        string $reason
    ): void {
        $payment->update([
            'status' => Payment::STATUS_REVIEW,
            'provider_transaction_id' =>
                isset($providerData['id'])
                    ? (string) $providerData['id']
                    : null,
            'channel' => $providerData['channel'] ?? null,
            'gateway_response' => $reason,
            'paid_at' => $paidAt,
            'verified_at' => now(),
        ]);

        $order->update([
            'status' => Order::STATUS_PAYMENT_REVIEW,
        ]);
    }
}

