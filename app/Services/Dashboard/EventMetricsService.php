<?php

namespace App\Services\Dashboard;

use App\Models\Event;
use Illuminate\Support\Facades\DB;

class EventMetricsService
{
    public function metrics(Event $event): array
    {
        $rows = DB::table('order_items as i')->join('orders as o', 'o.id', '=', 'i.order_id')->leftJoin('order_allocations as a', function ($j) {
            $j->on('a.order_id', '=', 'i.order_id')->on('a.seller_user_id', '=', 'i.seller_user_id');
        })->where('i.event_id', $event->id)
            ->whereIn('o.status', ['paid', 'registered', 'partially_refunded', 'refunded'])->groupBy('i.currency')
            ->selectRaw("i.currency, COUNT(DISTINCT o.id) AS orders, COUNT(DISTINCT LOWER(TRIM(o.customer_email))) AS buyers,
                SUM(i.quantity) AS product_units, SUM((i.quantity - i.refunded_quantity) * i.people_per_unit) AS admissions_covered,
                SUM(i.line_total_minor) AS gross_minor, SUM(i.discount_minor) AS discounts_minor,
                SUM(i.line_total_minor - i.discount_minor) AS charged_minor,
                SUM(i.platform_fee_minor) AS platform_fee_minor, SUM(i.refunded_minor) AS refunds_minor, SUM(i.provider_fee_minor) AS provider_fee_minor, SUM(CASE WHEN i.provider_fee_minor IS NULL AND i.line_total_minor > i.discount_minor THEN 1 ELSE 0 END) AS unknown_provider_fee_items,
                SUM(i.line_total_minor - i.discount_minor - i.platform_fee_minor) AS seller_net_minor,
                SUM(CASE WHEN i.refunded_quantity = i.quantity THEN i.platform_fee_minor ELSE 0 END) AS platform_fee_reversed_minor,
                SUM(CASE WHEN a.settlement_status = 'settled' THEN i.line_total_minor - i.discount_minor - i.platform_fee_minor ELSE 0 END) AS confirmed_settled_minor,
                SUM(CASE WHEN COALESCE(a.settlement_status,'pending') != 'settled' AND i.refunded_quantity < i.quantity THEN i.line_total_minor - i.discount_minor - i.platform_fee_minor ELSE 0 END) AS pending_settlement_minor,
                SUM(CASE WHEN a.settlement_status = 'settled' AND i.refunded_quantity = i.quantity THEN i.line_total_minor - i.discount_minor - i.platform_fee_minor ELSE 0 END) AS seller_recovery_due_minor,
                SUM(CASE WHEN o.status = 'refunded' THEN i.line_total_minor - i.discount_minor ELSE 0 END) AS refunded_order_totals_minor")
            ->get()->map(function ($r) {
                $a = (array) $r;
                foreach ($a as $k => &$v) {
                    if ($k !== 'currency') {
                        $v = (int) $v;
                    }
                }
                unset($v);
                // A refunded order flag is not a provider-confirmed refund amount or settlement.
                $a['refund_tracking_supported'] = true;
                $a['legacy_refund_amounts_unknown'] = $a['refunded_order_totals_minor'] > $a['refunds_minor'];
                if ($a['unknown_provider_fee_items'] > 0) {
                    $a['provider_fee_minor'] = null;
                }
                $a['seller_net_is_settled'] = false;
                $a['seller_net_before_refunds_minor'] = $a['seller_net_minor'];
                $a['seller_net_minor'] -= $a['refunds_minor'] - $a['platform_fee_reversed_minor'];
                $a['platform_net_minor'] = $a['provider_fee_minor'] === null ? null : $a['platform_fee_minor'] - $a['platform_fee_reversed_minor'] - $a['provider_fee_minor'];
                $a['provider_fee_bearer'] = 'platform';

                return $a;
            })->all();
        $attendance = DB::table('attendees')->where('event_id', $event->id)->selectRaw('SUM(CASE WHEN entries > 0 THEN 1 ELSE 0 END) AS attended, SUM(CASE WHEN inside = 1 THEN 1 ELSE 0 END) AS inside_count')->first();

        return ['by_currency' => $rows, 'actual_attendance' => (int) $attendance->attended, 'currently_inside' => (int) $attendance->inside_count];
    }
}
