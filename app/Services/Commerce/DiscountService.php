<?php

namespace App\Services\Commerce;

use App\Models\EventDiscount;
use App\Models\Order;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DiscountService
{
    // Call only after sorted inventory locks. Every quota-changing path uses this same row lock.
    public function apply(array $quote, ?array $input, bool $lock = false): array
    {
        if (! $input) {
            return $quote;
        }
        $query = EventDiscount::where('event_id', $input['event_id'])->where('code', strtoupper(trim($input['code'])));
        $offer = ($lock ? $query->lockForUpdate() : $query)->first();
        if (! $offer || ! $this->available($offer) || $offer->currency !== $quote['currency'] || $this->committed($offer->id) >= $offer->usage_limit) {
            $this->invalid();
        }
        $eligible = array_filter($quote['items'], fn ($line) => $line['event_id'] == $offer->event_id && (! $offer->ticket_type_ids || in_array($line['ticket_type_id'], $offer->ticket_type_ids, true)));
        $gross = array_sum(array_column($eligible, 'line_total_minor'));
        if ($gross <= 0) {
            $this->invalid();
        }
        $amount = $offer->type === 'percent' ? intdiv($gross * $offer->value, 100) : min($gross, $offer->value);
        if ($amount <= 0) {
            $this->invalid();
        }
        // Integer pro-rata allocation with a stable final remainder; sums exactly to the order discount.
        $remaining = $amount;
        $grossRemaining = $gross;
        foreach ($quote['items'] as $i => &$line) {
            $line['discount_minor'] = 0;
            if (! isset($eligible[$i])) {
                continue;
            }
            $part = $grossRemaining === $line['line_total_minor'] ? $remaining : \App\Support\Money::proportional($remaining, $line['line_total_minor'], $grossRemaining);
            $line['discount_minor'] = $part;
            $remaining -= $part;
            $grossRemaining -= $line['line_total_minor'];
        }
        unset($line);
        $quote['discount_minor'] = $amount;
        $quote['total_minor'] -= $amount;
        $quote['discount'] = ['id' => $offer->id, 'event_id' => $offer->event_id, 'code' => $offer->code, 'type' => $offer->type, 'value' => $offer->value, 'currency' => $offer->currency, 'version' => $offer->version];

        return $quote;
    }

    public function reserve(Order $order, array $quote): void
    {
        if (! isset($quote['discount'])) {
            return;
        }
        DB::table('discount_redemptions')->insert(['discount_id' => $quote['discount']['id'], 'order_id' => $order->id, 'snapshot' => json_encode($quote['discount'], JSON_THROW_ON_ERROR), 'amount_minor' => $quote['discount_minor'], 'held_until' => $order->expires_at, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function lockForOrder(Order $order): ?EventDiscount
    {
        $id = DB::table('discount_redemptions')->where('order_id', $order->id)->value('discount_id');

        return $id ? EventDiscount::whereKey($id)->lockForUpdate()->firstOrFail() : null;
    }

    public function canReacquire(Order $order, ?EventDiscount $offer, bool $retry = false): bool
    {
        return ! $offer || ((! $retry || $this->available($offer)) && $this->committed($offer->id, $order->id) < $offer->usage_limit);
    }

    public function redeem(Order $order): void
    {
        DB::table('discount_redemptions')->where('order_id', $order->id)->whereNull('redeemed_at')->update(['redeemed_at' => now(), 'updated_at' => now()]);
    }

    public function release(Order $order): void
    {
        DB::table('discount_redemptions')->where('order_id', $order->id)->whereNull('redeemed_at')->update(['held_until' => null, 'updated_at' => now()]);
    }

    public function reacquire(Order $order): void
    {
        DB::table('discount_redemptions')->where('order_id', $order->id)->whereNull('redeemed_at')->update(['held_until' => $order->expires_at, 'updated_at' => now()]);
    }

    public function committed(int $id, ?int $excludeOrder = null): int
    {
        $query = DB::table('discount_redemptions')->where('discount_id', $id)
            ->when($excludeOrder, fn ($q) => $q->where('order_id', '!=', $excludeOrder))
            ->where(fn ($q) => $q->whereNotNull('redeemed_at')->orWhere('held_until', '>', now()));

        // A locking read avoids a stale repeatable-read snapshot established by event eager loads.
        return (DB::transactionLevel() ? $query->lockForUpdate() : $query)->count();
    }

    private function available(EventDiscount $offer): bool
    {
        return $offer->active && (! $offer->starts_at || $offer->starts_at->lte(now())) && (! $offer->ends_at || $offer->ends_at->gt(now()));
    }

    private function invalid(): never
    {
        throw ValidationException::withMessages(['discount.code' => ['This discount is unavailable for this checkout.']]);
    }
}
