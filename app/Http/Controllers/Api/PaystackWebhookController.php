<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ReconcilePayment;
use App\Models\Payment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PaystackWebhookController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $secret = (string) config('services.paystack.secret_key');
        if ($secret === '' || ! hash_equals(hash_hmac('sha512', $request->getContent(), $secret), (string) $request->header('x-paystack-signature', ''))) {
            return response()->json(['ok' => false], $secret === '' ? 500 : 401);
        }
        $data = $request->json()->all();
        $reference = $data['data']['reference'] ?? null;
        if (($data['event'] ?? '') === 'charge.success' && is_string($reference)) {
            $payment = Payment::where('reference', $reference)->first();
            if (! $payment && strlen($reference) <= 100) {
                DB::table('payment_resolutions')->insertOrIgnore([
                    'provider_reference' => $reference, 'reason' => 'unknown_signed_charge',
                    'evidence' => json_encode(array_intersect_key($data['data'] ?? [], array_flip(['id', 'reference', 'amount', 'currency', 'status'])), JSON_THROW_ON_ERROR),
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            if ($payment) {
                DB::table('payment_reconciliations')->updateOrInsert(['payment_id' => $payment->id], [
                    'next_attempt_at' => now(), 'finished_at' => null, 'updated_at' => now(),
                ]);
                // Acknowledgement follows durable persistence. Scheduler recovery
                // covers unavailable queues and crashes before dispatch.
                try {
                    ReconcilePayment::dispatch($payment->id);
                } catch (\Throwable $exception) {
                    report($exception);
                }
            }
        }

        return response()->json(['ok' => true]);
    }
}
