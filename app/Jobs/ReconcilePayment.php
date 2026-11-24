<?php

namespace App\Jobs;

use App\Models\Payment;
use App\Services\Commerce\PaymentFinalizer;
use App\Services\Commerce\PaystackService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

class ReconcilePayment implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 300;

    public function uniqueId(): string
    {
        return (string) $this->paymentId;
    }

    public int $tries = 3;

    public int $timeout = 30;

    public function __construct(public int $paymentId) {}

    public function handle(PaystackService $provider, PaymentFinalizer $finalizer): void
    {
        $claimed = DB::table('payment_reconciliations')->where('payment_id', $this->paymentId)
            ->whereNull('finished_at')->where('next_attempt_at', '<=', now())
            ->update(['next_attempt_at' => now()->addMinutes(5), 'attempts' => DB::raw('attempts + 1'), 'updated_at' => now()]);
        if (! $claimed) {
            return;
        }
        $payment = Payment::findOrFail($this->paymentId);
        try {
            if (! in_array($payment->status, [Payment::STATUS_SUCCESS, Payment::STATUS_REVIEW], true)) {
                // No order or inventory transaction is open during the HTTP request.
                $finalizer->finalizePaystack($payment->reference, $provider->verify($payment->reference));
            }
            $payment->refresh();
            $terminal = in_array($payment->status, [Payment::STATUS_SUCCESS, Payment::STATUS_REVIEW], true);
            if (! $terminal && $payment->created_at->lt(now()->subDays(14))) {
                DB::table('payment_resolutions')->insertOrIgnore([
                    'payment_id' => $payment->id, 'reason' => 'unresolved_provider_status',
                    'evidence' => json_encode(['reference' => $payment->reference, 'status' => $payment->status]),
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                $terminal = true;
            }
            DB::table('payment_reconciliations')->where('payment_id', $payment->id)->update([
                'finished_at' => $terminal ? now() : null, 'last_error' => null,
                'next_attempt_at' => now()->addMinutes($payment->status === Payment::STATUS_FAILED ? 360 : 5), 'updated_at' => now(),
            ]);
        } catch (\Throwable $exception) {
            DB::table('payment_reconciliations')->where('payment_id', $payment->id)->update([
                'last_error' => 'Provider reconciliation failed: '.class_basename($exception),
                'next_attempt_at' => now()->addMinutes(5), 'updated_at' => now(),
            ]);
            throw $exception;
        }
    }
}
