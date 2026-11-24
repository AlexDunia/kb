<?php

namespace App\Services;

use App\Models\Event;
use App\Models\Order;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Services\Commerce\DiscountService;
use App\Services\Commerce\FinancialAudit;
use App\Services\Commerce\RefundService;
use Illuminate\Support\Facades\DB;

class EventLifecycleService
{
    public function change(Event $event, int $actor, array $input): Event
    {
        return DB::transaction(function () use ($event, $actor, $input) {
            $e = Event::whereKey($event->id)->lockForUpdate()->firstOrFail();
            app(EventAccessService::class)->authorize($e, $actor, 'owner');
            abort_unless((int) $e->lock_version === (int) $input['version'], 409, 'This event changed. Reload before continuing.');
            $e->ticketTypes()->orderBy('id')->lockForUpdate()->get();
            $from = $e->lifecycle_state;
            $action = $input['action'];
            $to = $from;
            if ($action === 'close_sales') {
                abort_unless(in_array($from, ['open', 'sales_closed'], true), 409);
                $to = 'sales_closed';
            }
            if ($action === 'open_sales') {
                abort_unless($from === 'sales_closed' && $e->status === 'active' && $e->ends_at?->isFuture() && (! $e->booking_cutoff_at || $e->booking_cutoff_at->isFuture()), 409, 'This event cannot reopen sales.');
                $to = 'open';
            }
            if ($action === 'set_cutoff') {
                abort_unless(in_array($from, ['open', 'sales_closed'], true), 409);
                $cutoff = empty($input['booking_cutoff_at']) ? null : \Carbon\CarbonImmutable::parse($input['booking_cutoff_at'])->utc();
                abort_if($cutoff && (! $e->ends_at || $cutoff->gt($e->ends_at)), 422, 'Booking cutoff must be no later than event end.');
                $e->booking_cutoff_at = $cutoff;
            }
            if ($action === 'complete') {
                abort_unless(in_array($from, ['open', 'sales_closed'], true) && $e->status === 'active' && $e->ends_at?->lte(now()), 409, 'An event can complete after its scheduled end.');
                $to = 'completed';
                $e->completed_at = now();
            }
            if ($action === 'cancel') {
                abort_unless(in_array($from, ['open', 'sales_closed'], true), 409, 'This event cannot be cancelled in its current state.');
                $to = 'cancelled';
                $e->cancelled_at = now();
                $legacy = DB::table('purchases')->where('event_id', $e->id)->whereIn('status', ['completed', 'pending'])->count();
                if ($legacy) {
                    FinancialAudit::record('event.legacy_purchase_obligations', ['records' => $legacy], $actor, $e->id);
                }
                DB::table('event_cancellation_work')->insertOrIgnore(['event_id' => $e->id, 'actor_id' => $actor, 'next_attempt_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
            }
            if ($action === 'archive') {
                abort_unless(in_array($from, ['completed', 'cancelled'], true) || ($e->status === 'draft' && ! $e->itemsExist()), 409, 'Complete or cancel this event before archiving.');
                abort_if(DB::table('event_cancellation_work')->where('event_id', $e->id)->where('finished', false)->exists(), 409, 'Cancellation processing is still outstanding.');
                abort_if(DB::table('commerce_refund_items as ri')->join('order_items as i', 'i.id', '=', 'ri.order_item_id')->join('commerce_refunds as r', 'r.id', '=', 'ri.refund_id')->where('i.event_id', $e->id)->whereNotIn('r.status', ['processed', 'voided'])->exists(), 409, 'Refund obligations are still outstanding.');
                abort_if(DB::table('order_items as i')->join('order_allocations as a', function ($j) {
                    $j->on('a.order_id', '=', 'i.order_id')->on('a.seller_user_id', '=', 'i.seller_user_id');
                })->join('orders as o', 'o.id', '=', 'i.order_id')->where('i.event_id', $e->id)->whereIn('o.status', ['paid', 'partially_refunded'])->whereRaw('a.seller_net_minor > a.seller_refund_liability_minor + a.settled_minor')->exists(), 409, 'Seller settlement obligations are still outstanding.');
                if ($from === 'cancelled') {
                    abort_if(DB::table('order_items as i')->join('orders as o', 'o.id', '=', 'i.order_id')->where('i.event_id', $e->id)->whereColumn('i.refunded_quantity', '<', 'i.quantity')->whereIn('o.status', ['paid', 'registered', 'partially_refunded', 'payment_review'])->exists(), 409, 'Cancelled-event financial or registration obligations are unresolved.');
                }
                abort_if(DB::table('purchases')->where('event_id', $e->id)->whereIn('status', ['completed', 'pending'])->exists(), 409, 'Legacy purchase obligations require reconciliation before archiving.');
                abort_if(DB::table('order_items as i')->join('order_allocations as a', function ($j) {
                    $j->on('a.order_id', '=', 'i.order_id')->on('a.seller_user_id', '=', 'i.seller_user_id');
                })->where('i.event_id', $e->id)->where('a.settled_minor', '>', 0)->where('a.seller_refund_liability_minor', '>', 0)->exists(), 409, 'Seller recovery obligations require reconciliation before archiving.');
                $to = 'archived';
                $e->archived_at = now();
            }
            $e->forceFill(['lifecycle_state' => $to, 'lock_version' => $e->lock_version + 1])->save();
            FinancialAudit::record('event.'.$action, ['from' => $from, 'to' => $to, 'reason' => $input['reason'] ?? null, 'booking_cutoff_at' => $e->booking_cutoff_at?->toIso8601String()], $actor, $e->id);

            return $e;
        }, 3);
    }

    public function cancelOrders(int $workId): void
    {
        $w = DB::table('event_cancellation_work')->find($workId);
        if (! $w || $w->finished) {
            return;
        }
        $event = Event::findOrFail($w->event_id);
        $ids = DB::table('order_items')->where('event_id', $event->id)->where('order_id', '>', $w->cursor)->distinct()->orderBy('order_id')->limit(50)->pluck('order_id');
        foreach ($ids as $id) {
            DB::transaction(function () use ($id, $event, $w) {
                $o = Order::whereKey($id)->lockForUpdate()->firstOrFail();
                $types = $o->items()->orderBy('ticket_type_id')->pluck('ticket_type_id');
                TicketType::whereIn('id', $types)->orderBy('id')->lockForUpdate()->get();
                app(DiscountService::class)->lockForOrder($o);
                if (in_array($o->status, ['pending_payment', 'payment_failed', 'expired'], true)) {
                    $o->update(['status' => 'cancelled']);
                    app(DiscountService::class)->release($o);
                    FinancialAudit::record('order.cancelled_for_event', [], null, $event->id, $o->id);
                } elseif (in_array($o->status, ['paid', 'registered', 'partially_refunded'], true)) {
                    $items = $o->items()->where('event_id', $event->id)->whereNotIn('id', DB::table('commerce_refund_items')->select('order_item_id'))->pluck('id')->all();
                    if ($items) {
                        $net = (int) $o->items()->whereIn('id', $items)->selectRaw('SUM(line_total_minor-discount_minor) AS amount')->value('amount');
                        $verified = $o->payments()->where('status', 'success')->whereNotNull('provider_transaction_id')->exists();
                        if ($net > 0 && ! $verified) {
                            FinancialAudit::record('event.refund_attention', ['reason' => 'Verified payment identity is missing; reconcile before refunding'], null, $event->id, $o->id);
                        } else {
                            app(RefundService::class)->request($event, $w->actor_id, $o, $items, hash('sha256', 'event-cancel:'.$event->id.':'.$o->id), 'Event cancelled', true);
                        }
                    }
                }
                // Event state already denies new entry. Lock credentials to serialize with in-flight scans.
                $tickets = Ticket::where('event_id', $event->id)->whereIn('order_item_id', $o->items()->where('event_id', $event->id)->pluck('id'))->orderBy('id')->lockForUpdate()->get();
                Ticket::whereIn('id', $tickets->pluck('id'))->whereIn('status', ['valid', 'refund_pending'])->update(['status' => 'void']);
                FinancialAudit::notify($o->id, 'event-cancelled:'.$event->id.':'.$o->id, 'cancellation', 'Your event was cancelled', 'The event "'.$event->title.'" has been cancelled. Admission credentials for this event are no longer valid. Eligible paid purchases enter refund processing; this message does not confirm a completed refund.', $event->id);
                DB::table('event_cancellation_work')->where('id', $w->id)->where('cursor', '<', $id)->update(['cursor' => $id, 'updated_at' => now()]);
            }, 3);
        }
        if ($ids->count() < 50) {
            DB::table('event_cancellation_work')->where('id', $w->id)->update(['finished' => true, 'last_error' => null, 'updated_at' => now()]);
        }
    }
}
