<?php

namespace App\Services\Commerce;

use App\Models\Order;
use App\Models\OrderAllocation;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\PayoutAccount;
use App\Models\TicketType;
use App\Services\Dashboard\EventAttributionService;
use App\Support\Money;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class CheckoutService
{
    public function __construct(
        private readonly InventoryService $inventory,
        private readonly PaystackService $paystack,
        private readonly PaymentFinalizer $finalizer,
        private readonly EventAttributionService $attribution
    ) {}

    public function quote(array $items): array
    {
        $items = $this->normalizedItems($items);
        $ids = array_column($items, 'ticket_type_id');

        $types = TicketType::query()
            ->with('event')
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');

        return $this->buildQuote(
            $items,
            $types,
            $this->inventory->reservedQuantities($ids)
        );
    }

    public function initialize(
        array $payload,
        ?int $userId
    ): array {
        $token = (string) $payload['checkout_token'];
        $hash = hash('sha256', $token);
        $customer = $this->normalizedCustomer($payload['customer']);
        $items = $this->normalizedItems($payload['items']);
        $visitorId = isset($payload['visitor_id'])
            && is_string($payload['visitor_id'])
            ? strtolower(trim($payload['visitor_id']))
            : null;

        $visitorHash = $visitorId
            ? $this->attribution->hashVisitor($visitorId)
            : null;

        $fingerprint = hash(
            'sha256',
            json_encode(
                [
                    'customer' => $customer,
                    'items' => $items,
                ],
                JSON_THROW_ON_ERROR
            )
        );

        return Cache::lock(
            "checkout:init:{$hash}",
            20
        )->block(
            6,
            function () use (
                $hash,
                $fingerprint,
                $customer,
                $items,
                $userId,
                $visitorId,
                $visitorHash
            ) {
                $existing = Order::query()
                    ->where('checkout_token_hash', $hash)
                    ->first();

                if ($existing) {
                    if (! hash_equals(
                        $existing->cart_fingerprint,
                        $fingerprint
                    )) {
                        throw ValidationException::withMessages([
                            'checkout_token' => [
                                'This checkout token belongs to a different cart or customer.',
                            ],
                        ]);
                    }

                    return $this->continueExistingOrder($existing);
                }

                [$order, $payment] = DB::transaction(
                    function () use (
                        $hash,
                        $fingerprint,
                        $customer,
                        $items,
                        $userId,
                        $visitorId,
                        $visitorHash
                    ) {
                        $ids = collect($items)
                            ->pluck('ticket_type_id')
                            ->sort()
                            ->values()
                            ->all();

                        $types = TicketType::query()
                            ->with('event')
                            ->whereIn('id', $ids)
                            ->orderBy('id')
                            ->lockForUpdate()
                            ->get()
                            ->keyBy('id');

                        $quote = $this->buildQuote(
                            $items,
                            $types,
                            $this->inventory->reservedQuantities($ids)
                        );

                        $eventIds = collect($quote['items'])
                            ->pluck('event_id')
                            ->map(fn ($id) => (int) $id)
                            ->unique()
                            ->values()
                            ->all();

                        $attribution = $visitorId
                            ? $this->attribution
                                ->latestAttributionForVisitor(
                                    $visitorId,
                                    $eventIds
                                )
                            : [];

                        $order = Order::query()->create([
                            'public_id' => (string) Str::ulid(),
                            'checkout_token_hash' => $hash,
                            'visitor_hash' => $visitorHash,
                            'cart_fingerprint' => $fingerprint,
                            'user_id' => $userId,
                            'customer_first_name' => $customer['first_name'],
                            'customer_last_name' => $customer['last_name'],
                            'customer_email' => $customer['email'],
                            'customer_phone' => $customer['phone'],
                            'currency' => $quote['currency'],
                            'subtotal_minor' => $quote['subtotal_minor'],
                            'discount_minor' => 0,
                            'total_minor' => $quote['total_minor'],
                            'status' => Order::STATUS_PENDING_PAYMENT,
                            'expires_at' => now()->addMinutes(
                                max(
                                    5,
                                    (int) config(
                                        'commerce.reservation_minutes',
                                        20
                                    )
                                )
                            ),
                        ]);

                        foreach ($quote['items'] as $line) {
                            $eventId = (int) $line['event_id'];
                            $touch = $attribution[$eventId] ?? null;

                            OrderItem::query()->create([
                                'order_id' => $order->id,
                                ...$line,
                                'source_code' =>
                                    $touch['source_code'] ?? null,
                                'attributed_event_view_id' =>
                                    $touch['view_id'] ?? null,
                                'currency' => $quote['currency'],
                            ]);
                        }

                        $this->createAllocations(
                            $order,
                            $quote['currency']
                        );

                        $payment = $this->createPayment($order);

                        return [$order, $payment];
                    },
                    3
                );

                // Intentionally outside the DB transaction. The application
                // never holds inventory row locks while waiting on Paystack.
                return $this->initializePayment(
                    $order,
                    $payment
                );
            }
        );
    }

    public function verify(
        string $reference,
        ?int $userId,
        ?string $checkoutToken
    ): Order {
        $payment = Payment::query()
            ->with('order')
            ->where('reference', $reference)
            ->firstOrFail();

        $this->authorizeOrderAccess(
            $payment->order,
            $userId,
            $checkoutToken
        );

        return $this->finalizer->finalizePaystack(
            $reference,
            $this->paystack->verify($reference)
        );
    }

    public function authorizeOrderAccess(
        Order $order,
        ?int $userId,
        ?string $checkoutToken
    ): void {
        if (
            $userId !== null
            && $order->user_id !== null
            && (int) $order->user_id === $userId
        ) {
            return;
        }

        if (
            is_string($checkoutToken)
            && $checkoutToken !== ''
            && hash_equals(
                $order->checkout_token_hash,
                hash('sha256', $checkoutToken)
            )
        ) {
            return;
        }

        abort(403, 'You do not have access to this order.');
    }

    public function publicOrderPayload(Order $order): array
    {
        $order->loadMissing('items.tickets');

        return [
            'public_id' => $order->public_id,
            'status' => $order->status,
            'currency' => $order->currency,
            'subtotal_minor' => (int) $order->subtotal_minor,
            'discount_minor' => (int) $order->discount_minor,
            'total_minor' => (int) $order->total_minor,
            'expires_at' => $order->expires_at?->toIso8601String(),
            'paid_at' => $order->paid_at?->toIso8601String(),
            'items' => $order->items
                ->map(
                    fn (OrderItem $item) => [
                        'id' => $item->id,
                        'event_id' => $item->event_id,
                        'event_title' => $item->event_title,
                        'ticket_type_id' => $item->ticket_type_id,
                        'ticket_name' => $item->ticket_name,
                        'unit_type' => $item->unit_type,
                        'people_per_unit' => (int) $item->people_per_unit,
                        'unit_price_minor' => (int) $item->unit_price_minor,
                        'quantity' => (int) $item->quantity,
                        'line_total_minor' => (int) $item->line_total_minor,
                        'tickets' => $order->isPaid()
                            ? $item->tickets
                                ->map(
                                    fn ($ticket) => [
                                        'public_id' => $ticket->public_id,
                                        'token' => $ticket->token,
                                        'admit_count' =>
                                            (int) $ticket->admit_count,
                                        'status' => $ticket->status,
                                    ]
                                )
                                ->values()
                                ->all()
                            : [],
                    ]
                )
                ->values()
                ->all(),
        ];
    }

    public function payload(
        Order $order,
        ?Payment $payment
    ): array {
        return [
            'order' => $this->publicOrderPayload($order),
            'payment' => $payment
                ? [
                    'reference' => $payment->reference,
                    'status' => $payment->status,
                    'access_code' => $payment->access_code,
                    'authorization_url' =>
                        $payment->authorization_url,
                ]
                : null,
        ];
    }

    private function continueExistingOrder(Order $order): array
    {
        $order->refresh();

        if (
            in_array(
                $order->status,
                [
                    Order::STATUS_PAID,
                    Order::STATUS_PAYMENT_REVIEW,
                    Order::STATUS_CANCELLED,
                    Order::STATUS_REFUNDED,
                ],
                true
            )
        ) {
            return $this->payload(
                $order,
                $order->payments()
                    ->latest('id')
                    ->first()
            );
        }

        if (
            $order->expires_at
            && $order->expires_at->isPast()
        ) {
            $order->update([
                'status' => Order::STATUS_EXPIRED,
            ]);

            throw ValidationException::withMessages([
                'checkout_token' => [
                    'This checkout reservation has expired. Start a new checkout attempt.',
                ],
            ]);
        }

        $payment = $order->payments()
            ->latest('id')
            ->first();

        if ($order->status === Order::STATUS_PAYMENT_FAILED) {
            $order->update([
                'status' => Order::STATUS_PENDING_PAYMENT,
            ]);
        }

        if (
            $payment
            && $payment->status === Payment::STATUS_INITIALIZED
            && $payment->access_code
        ) {
            return $this->payload($order, $payment);
        }

        if (
            ! $payment
            || in_array(
                $payment->status,
                [
                    Payment::STATUS_FAILED,
                    Payment::STATUS_REVIEW,
                ],
                true
            )
        ) {
            $payment = $this->createPayment($order);
        }

        return $this->initializePayment(
            $order,
            $payment
        );
    }

    private function initializePayment(
        Order $order,
        Payment $payment
    ): array {
        try {
            $result = $this->paystack->initialize(
                $order->loadMissing('allocations'),
                $payment
            );

            $payment->update([
                'status' => Payment::STATUS_INITIALIZED,
                'access_code' => $result['access_code'],
                'authorization_url' => $result['authorization_url'],
            ]);

            $order->allocations()->update([
                'settlement_mode' => $result['split_used']
                    ? 'paystack_split'
                    : 'platform_held',
            ]);

            return $this->payload(
                $order->fresh(),
                $payment->fresh()
            );
        } catch (Throwable $exception) {
            $payment->update([
                'status' => Payment::STATUS_FAILED,
                'gateway_response' => Str::limit(
                    $exception->getMessage(),
                    255,
                    ''
                ),
            ]);

            $order->update([
                'status' => Order::STATUS_PAYMENT_FAILED,
            ]);

            throw $exception;
        }
    }

    private function createPayment(Order $order): Payment
    {
        return Payment::query()->create([
            'order_id' => $order->id,
            'provider' => 'paystack',
            'reference' => 'KT-' . Str::ulid(),
            'amount_minor' => (int) $order->total_minor,
            'currency' => $order->currency,
            'status' => Payment::STATUS_CREATED,
        ]);
    }

    private function createAllocations(
        Order $order,
        string $currency
    ): void {
        $feeBps = max(
            0,
            min(
                10000,
                (int) config('commerce.platform_fee_bps', 0)
            )
        );

        foreach (
            $order->items()
                ->select(
                    'seller_user_id',
                    DB::raw(
                        'SUM(line_total_minor) AS gross_minor'
                    )
                )
                ->groupBy('seller_user_id')
                ->get()
            as $group
        ) {
            $gross = (int) $group->gross_minor;
            $fee = intdiv(
                $gross * $feeBps,
                10000
            );

            $payout = $group->seller_user_id
                ? PayoutAccount::query()
                    ->where(
                        'user_id',
                        $group->seller_user_id
                    )
                    ->where('status', 'active')
                    ->first()
                : null;

            OrderAllocation::query()->create([
                'order_id' => $order->id,
                'seller_user_id' => $group->seller_user_id,
                'payout_account_id' => $payout?->id,
                'provider_subaccount_code' =>
                    $payout?->provider_subaccount_code,
                'gross_minor' => $gross,
                'platform_fee_minor' => $fee,
                'seller_net_minor' => max(
                    0,
                    $gross - $fee
                ),
                'currency' => $currency,
                'settlement_mode' => 'platform_held',
                'settlement_status' => 'pending',
            ]);
        }
    }

    private function buildQuote(
        array $items,
        $types,
        array $reserved
    ): array {
        $lines = [];
        $subtotal = 0;
        $errors = [];

        foreach ($items as $index => $requested) {
            $id = (int) $requested['ticket_type_id'];
            $quantity = (int) $requested['quantity'];
            $type = $types->get($id);

            if (
                ! $type
                || ! $type->event
                || $type->event->status !== 'active'
                || $type->event->ticket_mode !== 'paid'
                || ! $type->visible
                || $type->event->created_by === null
            ) {
                $errors["items.{$index}"] = [
                    'This ticket type is not currently available.',
                ];
                continue;
            }

            if (
                ($type->sales_start_date
                    && $type->sales_start_date->isFuture())
                || ($type->sales_end_date
                    && $type->sales_end_date->isPast())
            ) {
                $errors["items.{$index}"] = [
                    'Ticket sales are not currently available.',
                ];
                continue;
            }

            $available = $this->inventory->availableQuantity(
                (int) $type->quantity,
                (int) ($reserved[$type->id] ?? 0)
            );

            if (
                $quantity > $available
                || (
                    $type->max_per_person
                    && $quantity > (int) $type->max_per_person
                )
            ) {
                $errors["items.{$index}"] = [
                    'Ticket quantity is unavailable.',
                ];
                continue;
            }

            $price = Money::decimalToMinor($type->price);

            if ($price <= 0) {
                $errors["items.{$index}"] = [
                    'Free tickets are not handled by paid checkout.',
                ];
                continue;
            }

            $lineTotal = $price * $quantity;

            $lines[] = [
                'ticket_type_id' => $type->id,
                'event_id' => $type->event_id,
                'seller_user_id' => $type->event->created_by,
                'event_title' => $type->event->title,
                'ticket_name' => $type->name,
                'unit_type' => $type->unit_type ?: 'individual',
                'people_per_unit' => max(
                    1,
                    (int) $type->people_per_unit
                ),
                'unit_price_minor' => $price,
                'quantity' => $quantity,
                'line_total_minor' => $lineTotal,
            ];

            $subtotal += $lineTotal;
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return [
            'currency' => strtoupper(
                (string) config('commerce.currency', 'NGN')
            ),
            'subtotal_minor' => $subtotal,
            'discount_minor' => 0,
            'total_minor' => $subtotal,
            'items' => $lines,
        ];
    }

    private function normalizedCustomer(array $customer): array
    {
        return [
            'first_name' => trim(
                (string) $customer['first_name']
            ),
            'last_name' => trim(
                (string) $customer['last_name']
            ),
            'email' => mb_strtolower(
                trim((string) $customer['email'])
            ),
            'phone' => trim(
                (string) $customer['phone']
            ),
        ];
    }

    private function normalizedItems(array $items): array
    {
        return collect($items)
            ->map(
                fn (array $item) => [
                    'ticket_type_id' =>
                        (int) $item['ticket_type_id'],
                    'quantity' =>
                        (int) $item['quantity'],
                ]
            )
            ->sortBy('ticket_type_id')
            ->values()
            ->all();
    }
}
