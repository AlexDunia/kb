<?php

namespace App\Jobs;

use App\Models\Order;
use App\Models\Payment;
use App\Services\Commerce\FinancialAudit;
use App\Services\Commerce\PaystackService;
use App\Services\Commerce\ProviderFeeService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

class ReconcileSettlement implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 300;

    public int $tries = 1;

    public int $timeout = 45;

    public function __construct(public int $settlementId) {}

    public function uniqueId(): string
    {
        return (string) $this->settlementId;
    }

    public function handle(PaystackService $provider): void
    {
        if (! DB::table('provider_settlements')->where('id', $this->settlementId)->where('reconciliation_status', 'pending')->where('status', 'success')->where('next_attempt_at', '<=', now())->update(['next_attempt_at' => now()->addMinutes(5)])) {
            return;
        }
        $s = DB::table('provider_settlements')->find($this->settlementId);
        $f = DB::table('settlement_feeds')->find($s->feed_id);
        $account = $f->payout_account_id ? \App\Models\PayoutAccount::find($f->payout_account_id) : null;
        try {
            $body = $provider->request('GET', '/settlement/'.rawurlencode($s->provider_id).'/transactions', ['page' => $s->page, 'perPage' => 100]);
            foreach ($body['data'] as $row) {
                $reference = $row['reference'] ?? '';
                if (! is_string($reference) || strlen($reference) > 100) {
                    throw new \RuntimeException('Invalid settlement reference');
                }
                $p = Payment::where('reference', $reference)->first();
                $valid = $p && $p->status === 'success' && ($row['status'] ?? '') === 'success' && (string) ($row['id'] ?? '') === (string) $p->provider_transaction_id && ProviderFeeService::integer($row['amount'] ?? null) === $p->amount_minor && ($row['currency'] ?? '') === $p->currency && $p->currency === $s->currency;
                $allocation = $valid && $account ? DB::table('order_allocations')->where('order_id', $p->order_id)->where('provider_subaccount_code', $account->provider_subaccount_code)->where('settlement_mode', 'paystack_split')->first() : null;
                if ($account && ! $allocation) {
                    $valid = false;
                }
                DB::table('settlement_transactions')->insertOrIgnore(['settlement_id' => $s->id, 'reference' => $reference, 'payment_id' => $p?->id, 'allocation_id' => $allocation?->id, 'expected_minor' => $valid ? ($account ? (int) $allocation->seller_net_minor : (int) $p->amount_minor) : 0, 'status' => $valid ? 'matched' : 'review', 'scope_payment_key' => $valid ? $f->id.':'.$p->id : null]);
                if ($valid) {
                    DB::transaction(function () use ($p, $row) {
                        $order = Order::whereKey($p->order_id)->lockForUpdate()->firstOrFail();
                        $p = Payment::whereKey($p->id)->lockForUpdate()->firstOrFail();
                        app(ProviderFeeService::class)->record($p, $row['fees'] ?? null);
                    }, 3);
                }
            }
            if ($s->page < (int) ($body['meta']['pageCount'] ?? $s->page)) {
                DB::table('provider_settlements')->where('id', $s->id)->update(['page' => $s->page + 1, 'next_attempt_at' => now()]);

                return;
            }
            DB::transaction(function () use ($s, $account) {
                $s = DB::table('provider_settlements')->where('id', $s->id)->lockForUpdate()->first();
                if ($s->reconciliation_status !== 'pending') {
                    return;
                }
                $q = DB::table('settlement_transactions')->where('settlement_id', $s->id);
                $expected = (int) (clone $q)->sum('expected_minor');
                $fee = (int) $s->fees_minor;
                $valid = ! (clone $q)->where('status', 'review')->exists() && ($account ? $expected === (int) $s->effective_minor && $expected === (int) $s->total_minor : $expected - $fee === (int) $s->effective_minor && $expected - $fee === (int) $s->total_minor);
                // Split fee bearer is the platform. Any deduction/unknown line is held for operational review.
                if (! $valid) {
                    DB::table('provider_settlements')->where('id', $s->id)->update(['reconciliation_status' => 'review', 'last_error' => 'Settlement totals or transactions do not reconcile', 'updated_at' => now()]);
                    FinancialAudit::record('settlement.review', ['settlement_id' => $s->id]);

                    return;
                }
                foreach ((clone $q)->whereNotNull('allocation_id')->orderBy('allocation_id')->cursor() as $line) {
                    $a = DB::table('order_allocations')->where('id', $line->allocation_id)->lockForUpdate()->first();
                    if ($a->settled_minor > 0 && $a->settlement_status === 'settled') {
                        continue;
                    }
                    DB::table('order_allocations')->where('id', $a->id)->update(['settled_minor' => $line->expected_minor, 'settled_at' => $s->settled_at, 'settlement_status' => 'settled', 'updated_at' => now()]);
                }
                if (! $account) {
                    foreach ((clone $q)->whereNotNull('payment_id')->orderBy('payment_id')->cursor() as $line) {
                        $p = Payment::find($line->payment_id);
                        DB::table('order_allocations')->where('order_id', $p->order_id)->where('settlement_mode', 'platform_held')->update(['platform_received_at' => $s->settled_at, 'settlement_status' => 'platform_received', 'updated_at' => now()]);
                    }
                }
                DB::table('provider_settlements')->where('id', $s->id)->update(['reconciliation_status' => 'reconciled', 'last_error' => null, 'updated_at' => now()]);
                FinancialAudit::record('settlement.reconciled', ['settlement_id' => $s->id, 'scope' => $account ? 'seller' : 'platform', 'amount_minor' => $s->effective_minor]);
            }, 3);
        } catch (\Throwable $e) {
            DB::table('provider_settlements')->where('id', $s->id)->update(['last_error' => 'Settlement reconciliation failed: '.class_basename($e), 'next_attempt_at' => now()->addMinutes(10), 'updated_at' => now()]);
        }
    }
}
