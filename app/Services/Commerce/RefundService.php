<?php

namespace App\Services\Commerce;

use App\Models\Event;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Services\EventAccessService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RefundService
{
    public function request(Event $event, int $actor, Order $order, array $ids, string $key, string $reason, bool $cancellation = false): object
    {
        app(EventAccessService::class)->authorize($event, $actor, 'owner');
        sort($ids);
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $fingerprint = hash('sha256', json_encode([$event->id, $order->id, $ids, $reason], JSON_THROW_ON_ERROR));

        try {
            return DB::transaction(function () use ($event, $actor, $order, $ids, $key, $reason, $fingerprint, $cancellation) {
                $o = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
                $typeIds = $o->items()->orderBy('ticket_type_id')->pluck('ticket_type_id');
                TicketType::whereIn('id', $typeIds)->orderBy('id')->lockForUpdate()->get();
                app(DiscountService::class)->lockForOrder($o);
                $existing = DB::table('commerce_refunds')->where('requested_by', $actor)->where('request_key', $key)->first();
                if ($existing) {
                    abort_unless(hash_equals($existing->fingerprint, $fingerprint), 409, 'This request key belongs to a different refund.');

                    return $existing;
                }
                abort_unless(in_array($o->status, ['paid', 'registered', 'partially_refunded'], true), 409, 'This order cannot be refunded automatically.');
                $payment = $o->payments()->where('status', 'success')->orderBy('id')->lockForUpdate()->first();
                $items = $o->items()->where('event_id', $event->id)->whereIn('id', $ids)->orderBy('id')->get();
                abort_unless(count($ids) > 0 && $items->count() === count($ids), 422, 'Choose complete order items belonging to this event and order.');
                abort_if(DB::table('commerce_refund_items')->whereIn('order_item_id', $ids)->exists(), 409, 'These order items already have a refund request.');
                $tickets = Ticket::whereIn('order_item_id', $ids)->orderBy('id')->lockForUpdate()->get();
                if (! $cancellation) {
                    abort_if(DB::table('attendees')->whereIn('order_item_id', $ids)->where('entries', '>', 0)->lockForUpdate()->first(), 409, 'An admitted ticket cannot be refunded automatically.');
                }
                $amount = (int) $items->sum(fn ($i) => $i->line_total_minor - $i->discount_minor);
                abort_unless($amount === 0 || ($payment && $payment->provider_transaction_id), 409, 'A verified successful provider payment is required.');
                $committed = (int) DB::table('commerce_refunds')->where('payment_id', $payment?->id)->whereNotIn('status', ['voided'])->sum('amount_minor');
                abort_if($payment && $committed + $amount > $payment->amount_minor, 409, 'Refund commitments exceed the captured charge.');
                $public = (string) Str::ulid();
                $id = DB::table('commerce_refunds')->insertGetId(['public_id' => $public, 'reference' => 'RF-'.$public, 'order_id' => $o->id, 'payment_id' => $payment?->id, 'requested_by' => $actor, 'request_key' => $key, 'fingerprint' => $fingerprint, 'amount_minor' => $amount, 'currency' => $o->currency, 'reason' => $reason, 'status' => 'queued', 'next_attempt_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
                foreach ($items as $i) {
                    DB::table('commerce_refund_items')->insert(['refund_id' => $id, 'order_item_id' => $i->id, 'quantity' => $i->quantity, 'amount_minor' => $i->line_total_minor - $i->discount_minor, 'platform_fee_minor' => $i->platform_fee_minor, 'seller_net_minor' => $i->line_total_minor - $i->discount_minor - $i->platform_fee_minor]);
                }
                Ticket::whereIn('id', $tickets->pluck('id'))->where('status', 'valid')->update(['status' => 'refund_pending']);
                FinancialAudit::record('refund.requested', ['refund_id' => $id, 'item_ids' => $ids, 'amount_minor' => $amount], $actor, $event->id, $o->id);
                FinancialAudit::notify($o->id, 'refund-requested:'.$id, 'refund', 'Your refund request', 'A refund of '.$o->currency.' '.number_format($amount / 100, 2).' has been requested. It is not yet confirmed.', $event->id);

                return DB::table('commerce_refunds')->find($id);
            }, 3);
        } catch (\Illuminate\Database\UniqueConstraintViolationException $exception) {
            abort(409, 'This refund request or order item is already reserved. Reload before continuing.');
        }
    }

    public function cancelledCharge(Payment $p, Order $o, array $data): void
    {
        $event = Event::whereIn('id', $o->items()->select('event_id'))->where('lifecycle_state', 'cancelled')->first();
        if (! $event || $o->items()->whereHas('tickets')->exists() || $p->provider_transaction_id) {
            return;
        }
        $providerId = (string) ($data['id'] ?? '');
        if (! ctype_digit($providerId) || strlen($providerId) > 40 || Payment::where('provider_transaction_id', $providerId)->where('id', '!=', $p->id)->exists()) {
            return;
        }
        $p->forceFill(['provider_transaction_id' => $providerId])->save();
        app(ProviderFeeService::class)->record($p, $data['fees'] ?? null);
        $public = (string) Str::ulid();
        $ids = $o->items()->orderBy('id')->get();
        if (DB::table('commerce_refund_items')->whereIn('order_item_id', $ids->pluck('id'))->exists()) {
            return;
        }
        $id = DB::table('commerce_refunds')->insertGetId(['public_id' => $public, 'reference' => 'RF-'.$public, 'order_id' => $o->id, 'payment_id' => $p->id, 'requested_by' => $event->created_by, 'request_key' => hash('sha256', 'cancelled-charge:'.$p->id), 'fingerprint' => hash('sha256', 'cancelled-charge:'.$p->id), 'amount_minor' => $p->amount_minor, 'currency' => $p->currency, 'reason' => 'Payment arrived after event cancellation', 'status' => 'queued', 'next_attempt_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        foreach ($ids as $i) {
            DB::table('commerce_refund_items')->insert(['refund_id' => $id, 'order_item_id' => $i->id, 'quantity' => $i->quantity, 'amount_minor' => $i->line_total_minor - $i->discount_minor, 'platform_fee_minor' => $i->platform_fee_minor, 'seller_net_minor' => $i->line_total_minor - $i->discount_minor - $i->platform_fee_minor]);
        }
        $o->update(['status' => 'cancelled']);
        app(DiscountService::class)->release($o);
        FinancialAudit::record('refund.cancelled_charge_queued', ['refund_id' => $id, 'payment_id' => $p->id], null, $event->id, $o->id);
        FinancialAudit::notify($o->id, 'cancelled-charge:'.$p->id, 'refund', 'Your payment is being refunded', 'A payment arrived after this event was cancelled. A refund has been requested; it is not yet confirmed.', $event->id);
    }

    public function apply(int $id, ?array $data): void
    {
        $r = DB::table('commerce_refunds')->find($id);
        if (! $r) {
            return;
        }
        DB::transaction(function () use ($r, $data) {
            $o = Order::whereKey($r->order_id)->lockForUpdate()->firstOrFail();
            $ids = $o->items()->orderBy('ticket_type_id')->pluck('ticket_type_id');
            TicketType::whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
            app(DiscountService::class)->lockForOrder($o);
            $p = $r->payment_id ? Payment::whereKey($r->payment_id)->lockForUpdate()->firstOrFail() : null;
            $r = DB::table('commerce_refunds')->where('id', $r->id)->lockForUpdate()->first();
            if (in_array($r->status, ['processed', 'voided'], true)) {
                return;
            }
            $zero = $r->amount_minor == 0;
            if (! $zero) {
                $transaction = $data['transaction'] ?? null;
                $transactionMatches = is_array($transaction) ? (string) ($transaction['id'] ?? '') === (string) $p->provider_transaction_id && ($transaction['reference'] ?? null) === $p->reference : (string) $transaction === (string) $p->provider_transaction_id;
                $valid = $transactionMatches && ($data['reference'] ?? null) === $r->reference && ProviderFeeService::integer($data['amount'] ?? null) === (int) $r->amount_minor && ($data['currency'] ?? null) === $r->currency && isset($data['id']) && ctype_digit((string) $data['id']) && strlen((string) $data['id']) <= 40;
                if (! $valid) {
                    $this->attention($r, 'Provider refund identity or amount mismatch');

                    return;
                }
                $status = $data['status'] ?? '';
                DB::table('commerce_refunds')->where('id', $r->id)->update(['provider_id' => (string) $data['id'], 'updated_at' => now()]);
                if ($status !== 'processed') {
                    if (in_array($status, ['pending', 'processing'], true)) {
                        DB::table('commerce_refunds')->where('id', $r->id)->update(['status' => 'processing', 'next_attempt_at' => now()->addMinutes(10), 'last_error' => null]);
                    } else {
                        $this->attention($r, 'Provider refund status: '.substr((string) $status, 0, 40));
                    }

                    return;
                }
            }
            $items = DB::table('commerce_refund_items as ri')->join('order_items as i', 'i.id', '=', 'ri.order_item_id')->where('ri.refund_id', $r->id)->orderBy('i.id')->get(['ri.*', 'i.seller_user_id', 'i.event_id']);
            $tickets = Ticket::whereIn('order_item_id', $items->pluck('order_item_id'))->orderBy('id')->lockForUpdate()->get();
            foreach ($items as $i) {
                OrderItem::whereKey($i->order_item_id)->update(['refunded_quantity' => $i->quantity, 'refunded_minor' => $i->amount_minor]);
                DB::table('order_allocations')->where('order_id', $o->id)->where('seller_user_id', $i->seller_user_id)->update(['refunded_minor' => DB::raw('refunded_minor + '.(int) $i->amount_minor), 'seller_refund_liability_minor' => DB::raw('seller_refund_liability_minor + '.(int) $i->seller_net_minor), 'updated_at' => now()]);
            }
            Ticket::whereIn('id', $tickets->pluck('id'))->update(['status' => $zero ? 'void' : 'refunded']);
            // Keep inside/entry and movement history intact. Revocation is not a fictitious exit.
            if ($p) {
                $p->forceFill(['refunded_minor' => (int) $p->refunded_minor + (int) $r->amount_minor])->save();
            }
            $remaining = $o->items()->whereColumn('refunded_quantity', '<', 'quantity')->exists();
            $o->update(['status' => $remaining ? 'partially_refunded' : ($zero && ! $p ? 'cancelled' : 'refunded')]);
            DB::table('commerce_refunds')->where('id', $r->id)->update(['status' => $zero ? 'voided' : 'processed', 'processed_at' => now(), 'next_attempt_at' => null, 'last_error' => null, 'updated_at' => now()]);
            FinancialAudit::record($zero ? 'entitlement.voided' : 'refund.processed', ['refund_id' => $r->id, 'amount_minor' => $r->amount_minor], null, null, $o->id);
            FinancialAudit::notify($o->id, 'refund-confirmed:'.$r->id, 'refund', $zero ? 'Your registration was cancelled' : 'Your refund was processed', $zero ? 'Your admission credentials have been cancelled. No payment was charged.' : 'Paystack has confirmed your refund of '.$r->currency.' '.number_format($r->amount_minor / 100, 2).'. Your refunded admission credentials are no longer valid.');
        }, 3);
    }

    private function attention(object $r, string $reason): void
    {
        DB::table('commerce_refunds')->where('id', $r->id)->update(['status' => 'attention', 'last_error' => $reason, 'next_attempt_at' => null, 'updated_at' => now()]);
        FinancialAudit::record('refund.attention', ['refund_id' => $r->id, 'reason' => $reason], null, null, $r->order_id);
    }
}
