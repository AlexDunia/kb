<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\Order;
use App\Services\Commerce\CheckoutService;
use App\Services\EventAccessService;
use App\Services\EventMessagingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class EventMessageController extends Controller
{
    public function audience(Request $request, Event $event, EventMessagingService $service)
    {
        app(EventAccessService::class)->authorize($event, $request->user()->id, 'owner');
        $v = $request->validate(['purpose' => 'required|in:event_update,marketing', 'audience' => 'required|in:all_ticket_holders,ticket_types,checked_in', 'ticket_type_ids' => ['nullable', 'array', 'min:1', 'max:100', 'required_if:audience,ticket_types'], 'ticket_type_ids.*' => ['integer', 'distinct', Rule::exists('ticket_types', 'id')->where('event_id', $event->id)]]);
        $v['event_id'] = $event->id;
        $v['ticket_type_ids'] = $v['audience'] === 'ticket_types' ? $v['ticket_type_ids'] : null;
        $count = DB::query()->fromSub($service->audience((object) $v), 'audience')->distinct()->count('recipient_email');

        return response()->json(['data' => ['recipient_count' => $count]]);
    }

    public function index(Request $request, Event $event)
    {
        app(EventAccessService::class)->authorize($event, $request->user()->id, 'owner');
        $v = $request->validate(['page' => 'sometimes|integer|min:1', 'per_page' => 'sometimes|integer|min:1|max:100']);
        $page = DB::table('event_messages')->where('event_id', $event->id)->orderByDesc('id')->paginate($v['per_page'] ?? 20);
        $stats = DB::table('event_message_deliveries')->whereIn('message_id', $page->getCollection()->pluck('id'))->groupBy('message_id', 'status')->selectRaw('message_id,status,COUNT(*) AS total')->get()->groupBy('message_id');
        $page->through(fn ($m) => [...\App\Support\UtcDates::row($m), 'ticket_type_ids' => json_decode($m->ticket_type_ids ?? 'null', true), 'delivery_counts' => ($stats[$m->id] ?? collect())->pluck('total', 'status')->map(fn ($n) => (int) $n)]);

        return response()->json($page);
    }

    public function save(Request $request, Event $event, ?int $message = null)
    {
        app(EventAccessService::class)->authorize($event, $request->user()->id, 'owner');
        $v = $request->validate(['version' => [$message ? 'required' : 'sometimes', 'integer', 'min:1'], 'subject' => ['required', 'string', 'max:150', 'not_regex:/[\r\n]/'], 'body' => 'required|string|max:20000', 'purpose' => 'required|in:event_update,marketing', 'audience' => 'required|in:all_ticket_holders,ticket_types,checked_in', 'ticket_type_ids' => ['nullable', 'array', 'min:1', 'max:100', 'required_if:audience,ticket_types'], 'ticket_type_ids.*' => ['integer', 'distinct', Rule::exists('ticket_types', 'id')->where('event_id', $event->id)]]);

        return DB::transaction(function () use ($event, $message, $v, $request) {
            $m = $message ? DB::table('event_messages')->where('event_id', $event->id)->where('id', $message)->lockForUpdate()->firstOrFail() : null;
            abort_if($m && ($m->status !== 'draft' || $m->version != $v['version']), 409, 'This message changed or has already been queued.');
            $row = [...$v, 'ticket_type_ids' => $v['audience'] === 'ticket_types' ? json_encode(array_map('intval', $v['ticket_type_ids']), JSON_THROW_ON_ERROR) : null, 'version' => $m ? $m->version + 1 : 1, 'updated_at' => now()];
            if ($m) {
                DB::table('event_messages')->where('id', $m->id)->update($row);
            } else {
                $message = DB::table('event_messages')->insertGetId([...$row, 'event_id' => $event->id, 'created_by' => $request->user()->id, 'created_at' => now()]);
            }
            $saved = DB::table('event_messages')->find($message);

            return response()->json(['data' => [...\App\Support\UtcDates::row($saved), 'ticket_type_ids' => json_decode($saved->ticket_type_ids ?? 'null', true)]], $m ? 200 : 201);
        }, 3);
    }

    public function send(Request $request, Event $event, int $message, EventMessagingService $service)
    {
        app(EventAccessService::class)->authorize($event, $request->user()->id, 'owner');
        $v = $request->validate(['version' => 'required|integer|min:1']);

        return DB::transaction(function () use ($event, $message, $v, $service) {
            $m = DB::table('event_messages')->where('event_id', $event->id)->where('id', $message)->lockForUpdate()->firstOrFail();
            if ($m->status !== 'draft') {
                return response()->json(['data' => ['id' => $message, 'status' => $m->status]], 202);
            }
            abort_if($m->version != $v['version'], 409, 'This draft changed. Reload before sending.');
            $max = $service->audience($m)->max('a.id') ?? 0;
            DB::table('event_messages')->where('id', $message)->update(['status' => 'preparing', 'version' => $m->version + 1, 'audience_max' => $max, 'queued_at' => now(), 'updated_at' => now()]);

            return response()->json(['data' => ['id' => $message, 'status' => 'preparing']], 202);
        }, 3);
    }

    public function retry(Request $request, Event $event, int $message)
    {
        app(EventAccessService::class)->authorize($event, $request->user()->id, 'owner');

        return DB::transaction(function () use ($event, $message) {
            $m = DB::table('event_messages')->where('event_id', $event->id)->where('id', $message)->lockForUpdate()->firstOrFail();
            abort_unless($m->status === 'failed', 409);
            DB::table('event_message_deliveries')->where('message_id', $message)->where('status', 'failed')->update(['status' => 'pending', 'attempts' => 0, 'next_attempt_at' => now(), 'updated_at' => now()]);
            DB::table('event_messages')->where('id', $message)->update(['status' => 'sending', 'updated_at' => now()]);

            return response()->json(['data' => ['id' => $message, 'status' => 'sending']], 202);
        }, 3);
    }

    public function preference(Request $request, string $publicId, CheckoutService $checkout)
    {
        $order = Order::where('public_id', $publicId)->firstOrFail();
        $checkout->authorizeOrderAccess($order, auth('sanctum')->id(), $request->header('X-Checkout-Token'));
        $v = $request->validate(['event_id' => 'required|integer', 'marketing_consent' => 'required|boolean']);
        abort_unless($order->isFulfilled() && $order->items()->where('event_id', $v['event_id'])->exists(), 403);
        if ($v['marketing_consent']) {
            $token = $request->header('X-Order-Recovery-Token');
            abort_unless(is_string($token) && DB::table('order_recoveries')->where('order_id', $order->id)->where('access_hash', hash('sha256', $token))->whereNotNull('exchanged_at')->where('expires_at', '>', now())->exists(), 403, 'Open your emailed order recovery link before opting in.');
        }
        // A verified buyer may consent only for their own stored address, never for named attendees.
        DB::table('event_message_preferences')->upsert([['event_id' => $v['event_id'], 'email' => mb_strtolower(trim($order->customer_email)), 'marketing_consent' => $v['marketing_consent'], 'consented_at' => $v['marketing_consent'] ? now() : null, 'unsubscribed_at' => $v['marketing_consent'] ? null : now(), 'created_at' => now(), 'updated_at' => now()]], ['event_id', 'email'], ['marketing_consent', 'consented_at', 'unsubscribed_at', 'updated_at']);

        return response()->json(['ok' => true]);
    }

    public function unsubscribe(Request $request, int $delivery)
    {
        $d = DB::table('event_message_deliveries as d')->join('event_messages as m', 'm.id', '=', 'd.message_id')->where('d.id', $delivery)->first(['m.event_id', 'd.email']);
        abort_unless($d, 404);
        if ($request->isMethod('get')) {
            $url = htmlspecialchars($request->fullUrl(), ENT_QUOTES, 'UTF-8');

            return response('<!doctype html><html lang="en"><meta name="viewport" content="width=device-width"><title>Message preferences</title><form method="post" action="'.$url.'"><p>Stop organiser messages for this event?</p><button>Unsubscribe</button></form></html>')->header('Referrer-Policy', 'no-referrer');
        }
        DB::table('event_message_preferences')->upsert([['event_id' => $d->event_id, 'email' => $d->email, 'marketing_consent' => false, 'unsubscribed_at' => now(), 'created_at' => now(), 'updated_at' => now()]], ['event_id', 'email'], ['marketing_consent', 'unsubscribed_at', 'updated_at']);

        return response()->json(['message' => 'You have unsubscribed from organiser messages for this event.']);
    }
}
