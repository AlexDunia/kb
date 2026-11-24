<?php

namespace App\Services\Commerce;

use App\Models\Payment;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

class ProviderFeeService
{
    public static function integer(mixed $v): ?int
    {
        if (! is_int($v) && ! (is_string($v) && ctype_digit($v))) {
            return null;
        }
        if (strlen((string) $v) > strlen((string) PHP_INT_MAX) || (strlen((string) $v) === strlen((string) PHP_INT_MAX) && strcmp((string) $v, (string) PHP_INT_MAX) > 0)) {
            return null;
        }

        return (int) $v >= 0 ? (int) $v : null;
    }

    public function record(Payment $p, mixed $value): void
    {
        $fee = self::integer($value);
        if ($fee === null || $fee > $p->amount_minor) {
            return;
        }
        if ($p->provider_fee_minor !== null && (int) $p->provider_fee_minor !== $fee) {
            FinancialAudit::record('payment.fee_mismatch', ['payment_id' => $p->id, 'observed_fee_minor' => $fee], null, null, $p->order_id);

            return;
        }
        $p->forceFill(['provider_fee_minor' => $fee])->save();
        $remaining = $fee;
        $gross = (int) $p->amount_minor;
        $items = $p->order->items()->orderBy('id')->get();
        foreach ($items as $item) {
            $net = $item->line_total_minor - $item->discount_minor;
            $part = $gross > 0 ? Money::proportional($remaining, $net, $gross) : 0;
            $item->update(['provider_fee_minor' => $part]);
            $remaining -= $part;
            $gross -= $net;
        }
        foreach ($items->groupBy('seller_user_id') as $seller => $rows) {
            DB::table('order_allocations')->where('order_id', $p->order_id)->where('seller_user_id', $seller)->update(['provider_fee_minor' => $rows->sum('provider_fee_minor')]);
        }
    }
}
