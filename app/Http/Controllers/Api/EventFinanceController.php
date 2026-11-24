<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\Order;
use App\Services\Commerce\RefundService;
use App\Services\EventAccessService;
use App\Services\EventLifecycleService;
use App\Support\UtcDates;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class EventFinanceController extends Controller
{
    public function lifecycle(Request $r, Event $event, EventLifecycleService $service)
    {
        $v = $r->validate(['version' => 'required|integer|min:1', 'action' => 'required|in:close_sales,open_sales,set_cutoff,complete,cancel,archive', 'reason' => 'required_if:action,cancel|nullable|string|min:3|max:300', 'booking_cutoff_at' => ['nullable', 'date', 'regex:/T.*(?:Z|[+-][0-9]{2}:[0-9]{2})$/']]);
        $e = $service->change($event, $r->user()->id, $v);

        return response()->json(['data' => ['id' => $e->id, 'version' => $e->lock_version, 'lifecycle_state' => $e->lifecycle_state, 'booking_cutoff_at' => $e->booking_cutoff_at?->toIso8601String()]]);
    }

    public function refunds(Request $r, Event $event)
    {
        app(EventAccessService::class)->authorize($event, $r->user()->id, 'owner');
        $v = $r->validate(['page' => 'sometimes|integer|min:1', 'per_page' => 'sometimes|integer|min:1|max:100']);
        $q = DB::table('commerce_refunds')->whereIn('id', DB::table('commerce_refund_items as ri')->join('order_items as i', 'i.id', '=', 'ri.order_item_id')->where('i.event_id', $event->id)->select('ri.refund_id'));

        return response()->json($q->orderByDesc('id')->paginate($v['per_page'] ?? 30)->through(fn ($x) => \Illuminate\Support\Arr::only(UtcDates::row($x), ['public_id', 'order_id', 'amount_minor', 'currency', 'status', 'reason', 'processed_at', 'created_at'])));
    }

    public function refund(Request $r, Event $event, string $publicId, RefundService $service)
    {
        $v = $r->validate(['request_key' => 'required|uuid', 'order_item_ids' => 'required|array|min:1|max:25', 'order_item_ids.*' => 'required|integer|distinct', 'reason' => 'required|string|min:3|max:300']);
        $o = Order::where('public_id', $publicId)->firstOrFail();
        $refund = $service->request($event, $r->user()->id, $o, $v['order_item_ids'], hash('sha256', $v['request_key']), $v['reason']);

        return response()->json(['data' => \Illuminate\Support\Arr::only(UtcDates::row($refund), ['public_id', 'amount_minor', 'currency', 'status', 'reason', 'processed_at', 'created_at'])], 202);
    }

    public function retry(Request $r, Event $event, string $refund)
    {
        app(EventAccessService::class)->authorize($event, $r->user()->id, 'owner');
        $row = DB::table('commerce_refunds')->where('public_id', $refund)->whereIn('id', DB::table('commerce_refund_items as ri')->join('order_items as i', 'i.id', '=', 'ri.order_item_id')->where('i.event_id', $event->id)->select('ri.refund_id'))->firstOrFail();
        DB::table('commerce_refunds')->where('id', $row->id)->where('status', 'attention')->update(['status' => 'queued', 'next_attempt_at' => now(), 'updated_at' => now()]);

        return response()->json(['data' => ['public_id' => $row->public_id, 'status' => DB::table('commerce_refunds')->where('id', $row->id)->value('status')]], 202);
    }

    public function earnings(Request $r, Event $event)
    {
        app(EventAccessService::class)->authorize($event, $r->user()->id, 'owner');

        return response()->json(['data' => app(\App\Services\Dashboard\EventMetricsService::class)->metrics($event)]);
    }

    public function audits(Request $r, Event $event)
    {
        app(EventAccessService::class)->authorize($event, $r->user()->id, 'owner');
        $v = $r->validate(['page' => 'sometimes|integer|min:1', 'per_page' => 'sometimes|integer|min:1|max:100']);

        return response()->json(DB::table('financial_audits')->where('event_id', $event->id)->orderByDesc('id')->paginate($v['per_page'] ?? 30)->through(fn ($x) => [...UtcDates::row($x), 'details' => json_decode($x->details, true)]));
    }
}
