<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\InitializeCheckoutRequest;
use App\Http\Requests\QuoteCheckoutRequest;
use App\Models\Order;
use App\Services\Commerce\CheckoutService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CheckoutController extends Controller
{
    public function quote(QuoteCheckoutRequest $request, CheckoutService $checkout): JsonResponse
    {
        return response()->json(['data' => $checkout->quote($request->validated('items'), $request->validated('discount'))]);
    }

    public function initialize(InitializeCheckoutRequest $request, CheckoutService $checkout): JsonResponse
    {
        return response()->json(['data' => $checkout->initialize($request->validated(), auth('sanctum')->id())], 201);
    }

    public function show(Request $request, string $id, CheckoutService $checkout): JsonResponse
    {
        $order = Order::query()->where('public_id', $id)->firstOrFail();
        $checkout->authorizeOrderAccess($order, auth('sanctum')->id(), $request->header('X-Checkout-Token'));

        return response()->json(['data' => $checkout->publicOrderPayload($order)]);
    }

    public function verify(Request $request, string $reference, CheckoutService $checkout): JsonResponse
    {
        $order = $checkout->verify($reference, auth('sanctum')->id(), $request->header('X-Checkout-Token'));

        return response()->json(['data' => $checkout->publicOrderPayload($order)]);
    }

    public function cancel(Request $request, string $publicId, CheckoutService $checkout): JsonResponse
    {
        $order = Order::where('public_id', $publicId)->firstOrFail();
        $checkout->authorizeOrderAccess($order, auth('sanctum')->id(), $request->header('X-Checkout-Token'));

        return \Illuminate\Support\Facades\DB::transaction(function () use ($order, $checkout) {
            $order = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $ids = $order->items()->orderBy('ticket_type_id')->pluck('ticket_type_id');
            \App\Models\TicketType::whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
            $offers = app(\App\Services\Commerce\DiscountService::class);
            $offers->lockForOrder($order);
            abort_unless(in_array($order->status, ['pending_payment', 'payment_failed', 'expired', 'cancelled'], true), 409, 'This order cannot be cancelled here.');
            $order->update(['status' => 'cancelled']);
            $offers->release($order);

            return response()->json(['data' => $checkout->publicOrderPayload($order)]);
        }, 3);
    }
}
