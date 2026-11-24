<?php

namespace App\Services\Commerce;

use App\Models\Order;
use Illuminate\Support\Facades\DB;

class InventoryService
{
    /** Caller holds ticket row locks when making inventory-affecting decisions. */
    public function commitments(array $ids, ?int $exclude = null): array
    {
        if (! $ids) {
            return [];
        }
        $rows = DB::table('order_items')->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->whereIn('order_items.ticket_type_id', $ids)
            ->when($exclude !== null, fn ($q) => $q->where('orders.id', '!=', $exclude))
            ->where(fn ($q) => $q->where('orders.status', Order::STATUS_PAID)->orWhere(fn ($q) => $q->where('orders.status', Order::STATUS_PENDING_PAYMENT)->where('orders.expires_at', '>', now())))
            ->selectRaw("order_items.ticket_type_id, SUM(CASE WHEN orders.status = 'paid' THEN order_items.quantity ELSE 0 END) sold_units, SUM(CASE WHEN orders.status = 'pending_payment' THEN order_items.quantity ELSE 0 END) reserved_units")
            ->groupBy('order_items.ticket_type_id')->get();
        $result = [];
        foreach ($rows as $row) {
            $result[(int) $row->ticket_type_id] = ['sold_units' => (int) $row->sold_units, 'reserved_units' => (int) $row->reserved_units];
        }
        // Historical purchases have no reservation expiry; pending records require explicit resolution.
        $legacy = DB::table('purchases')->whereIn('ticket_type_id', $ids)->whereIn('status', ['completed', 'pending'])
            ->selectRaw("ticket_type_id, SUM(CASE WHEN status = 'completed' THEN quantity ELSE 0 END) sold_units, SUM(CASE WHEN status = 'pending' THEN quantity ELSE 0 END) reserved_units")
            ->groupBy('ticket_type_id')->get();
        foreach ($legacy as $row) {
            $id = (int) $row->ticket_type_id;
            $result[$id] = ['sold_units' => ($result[$id]['sold_units'] ?? 0) + (int) $row->sold_units, 'reserved_units' => ($result[$id]['reserved_units'] ?? 0) + (int) $row->reserved_units];
        }

        return $result;
    }

    /** Historical method name retained: returns paid plus unexpired reserved units. */
    public function reservedQuantities(array $ids, ?int $exclude = null): array
    {
        return array_map(fn ($counts) => $counts['sold_units'] + $counts['reserved_units'], $this->commitments($ids, $exclude));
    }

    public function availableQuantity(int $capacity, int $reserved): int
    {
        return max(0, $capacity - $reserved);
    }
}
