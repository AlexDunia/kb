<?php

namespace App\Jobs;

use App\Services\Commerce\PaystackService;
use App\Services\Commerce\RefundService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

class ProcessRefund implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 300;

    public int $tries = 1;

    public int $timeout = 45;

    public function __construct(public int $refundId) {}

    public function uniqueId(): string
    {
        return (string) $this->refundId;
    }

    public function handle(PaystackService $provider, RefundService $service): void
    {
        $claim = DB::table('commerce_refunds')->where('id', $this->refundId)->whereIn('status', ['queued', 'processing'])->where('next_attempt_at', '<=', now())->update(['next_attempt_at' => now()->addMinutes(5), 'attempts' => DB::raw('attempts + 1'), 'updated_at' => now()]);
        if (! $claim) {
            return;
        }
        $r = DB::table('commerce_refunds')->find($this->refundId);
        try {
            if ($r->amount_minor == 0) {
                $service->apply($r->id, null);

                return;
            }
            $p = \App\Models\Payment::findOrFail($r->payment_id);
            $data = $provider->findRefund($r->reference);
            if (! $data) {
                $data = $provider->createRefund(['transaction' => $p->reference, 'reference' => $r->reference, 'amount' => (int) $r->amount_minor, 'currency' => $r->currency, 'merchant_note' => $r->reference, 'customer_note' => $r->reason]);
            }
            $service->apply($r->id, $data);
        } catch (\Throwable $e) {
            DB::table('commerce_refunds')->where('id', $r->id)->whereNotIn('status', ['processed', 'voided'])->update(['status' => $r->attempts >= 20 ? 'attention' : 'queued', 'last_error' => 'Reconciliation failed: '.class_basename($e), 'next_attempt_at' => $r->attempts >= 20 ? null : now()->addMinutes(10), 'updated_at' => now()]);
        }
    }
}
