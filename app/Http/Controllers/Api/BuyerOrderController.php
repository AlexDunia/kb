<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Ticket;
use App\Services\Commerce\CheckoutService;
use App\Services\Commerce\OrderRecoveryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BuyerOrderController extends Controller
{
    public function index(Request $request, CheckoutService $checkout): JsonResponse
    {
        $input = $request->validate(['page' => 'sometimes|integer|min:1', 'per_page' => 'sometimes|integer|min:1|max:50']);
        $orders = Order::where('user_id', $request->user()->id)->with('items.tickets')->orderByDesc('id')->paginate($input['per_page'] ?? 20);
        $orders->getCollection()->transform(fn ($order) => $checkout->publicOrderPayload($order));

        return response()->json($orders);
    }

    public function ticket(Request $request, string $publicId, CheckoutService $checkout): JsonResponse
    {
        $ticket = Ticket::where('public_id', $publicId)->with('orderItem.order')->firstOrFail();
        $order = $ticket->orderItem->order;
        $checkout->authorizeOrderAccess($order, auth('sanctum')->id(), $request->header('X-Checkout-Token'));
        abort_unless($order->isFulfilled(), 403);

        return response()->json(['data' => [
            'public_id' => $ticket->public_id, 'token' => $ticket->token,
            'admit_count' => $ticket->admit_count, 'status' => $ticket->status,
            'order_public_id' => $order->public_id,
            'event_title' => $ticket->orderItem->event_title, 'ticket_name' => $ticket->orderItem->ticket_name,
        ]]);
    }

    public function requestRecovery(Request $request): JsonResponse
    {
        $input = $request->validate(['order_public_id' => 'required|string|size:26', 'email' => 'required|email:rfc|max:255']);
        $order = Order::where('public_id', $input['order_public_id'])->first();
        if ($order && hash_equals(mb_strtolower($order->customer_email), mb_strtolower(trim($input['email'])))) {
            // Generic responses prevent public order/email enumeration. A single
            // request per order per ten-minute window limits email flooding.
            DB::table('commerce_deliveries')->insertOrIgnore([
                'order_id' => $order->id, 'deduplication_key' => 'recovery:'.$order->id.':'.intdiv(now()->timestamp, 600),
                'kind' => 'recovery', 'next_attempt_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return response()->json(['message' => 'If the order details match, a recovery link will be emailed.'], 202);
    }

    public function exchangeRecovery(Request $request, OrderRecoveryService $recovery): JsonResponse
    {
        $input = $request->validate(['token' => 'required|string|size:64']);

        return response()->json(['data' => $recovery->exchange($input['token'])]);
    }
}
