<?php

namespace App\Jobs;

use App\Services\Commerce\PaystackService;
use App\Services\Commerce\ProviderFeeService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

class ReconcileSettlementFeed implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 300;

    public int $tries = 1;

    public int $timeout = 45;

    public function __construct(public int $feedId) {}

    public function uniqueId(): string
    {
        return (string) $this->feedId;
    }

    public function handle(PaystackService $provider): void
    {
        if (! DB::table('settlement_feeds')->where('id', $this->feedId)->where('next_attempt_at', '<=', now())->update(['next_attempt_at' => now()->addMinutes(5)])) {
            return;
        }
        $f = DB::table('settlement_feeds')->find($this->feedId);
        try {
            $account = $f->payout_account_id ? \App\Models\PayoutAccount::findOrFail($f->payout_account_id) : null;
            if ($account && ! $account->provider_subaccount_id) {
                throw new \RuntimeException('Provider subaccount identity is unavailable');
            }
            $query = ['subaccount' => $account?->provider_subaccount_id ?? 'none', 'perPage' => 100, 'page' => $f->page];
            if ($f->from_at) {
                $query['from'] = $f->from_at;
            }
            $body = $provider->request('GET', '/settlement', $query);
            foreach ($body['data'] as $row) {
                if (! is_array($row) || ! ctype_digit((string) ($row['id'] ?? '')) || ! in_array($row['status'] ?? '', ['success', 'processing', 'pending', 'failed'], true) || ! preg_match('/^[A-Z]{3}$/', $row['currency'] ?? '')) {
                    throw new \RuntimeException('Invalid settlement identity');
                }
                foreach (['total_amount', 'effective_amount', 'total_fees'] as $field) {
                    if (ProviderFeeService::integer($row[$field] ?? null) === null) {
                        throw new \RuntimeException('Invalid settlement amount');
                    }
                }
                $values = ['status' => $row['status'], 'currency' => $row['currency'], 'total_minor' => $row['total_amount'], 'effective_minor' => $row['effective_amount'], 'fees_minor' => $row['total_fees'], 'settled_at' => empty($row['settlement_date']) ? null : \Carbon\Carbon::parse($row['settlement_date'])->utc(), 'provider_created_at' => empty($row['createdAt']) ? null : \Carbon\Carbon::parse($row['createdAt'])->utc(), 'updated_at' => now()];
                DB::transaction(function () use ($f, $row, $values) {
                    $old = DB::table('provider_settlements')->where('feed_id', $f->id)->where('provider_id', (string) $row['id'])->lockForUpdate()->first();
                    if ($old) {
                        $changed = $old->status !== $values['status'] || $old->currency !== $values['currency'] || $old->total_minor != $values['total_minor'] || $old->effective_minor != $values['effective_minor'];
                        $newlySuccessful = $old->status !== 'success' && $values['status'] === 'success' && $old->reconciliation_status !== 'reconciled' && ! DB::table('settlement_transactions')->where('settlement_id', $old->id)->exists();
                        if ($newlySuccessful) {
                            DB::table('provider_settlements')->where('id', $old->id)->update([...$values, 'reconciliation_status' => 'pending', 'next_attempt_at' => now(), 'last_error' => null]);

                            return;
                        }
                        DB::table('provider_settlements')->where('id', $old->id)->update([...$values, ...($changed ? ['reconciliation_status' => 'review', 'last_error' => 'Provider settlement evidence changed; operator reconciliation required'] : [])]);
                    } else {
                        DB::table('provider_settlements')->insert([...$values, 'feed_id' => $f->id, 'provider_id' => (string) $row['id'], 'next_attempt_at' => now(), 'created_at' => now()]);
                    }
                }, 3);
            }
            $more = $f->page < (int) ($body['meta']['pageCount'] ?? $f->page);
            $from = $f->scan_started_at ?? now();
            $nextFrom = \Carbon\Carbon::parse($from)->subDays(7);
            $pending = DB::table('provider_settlements')->where('feed_id', $f->id)->where('status', '!=', 'success')->min('provider_created_at');
            if ($pending && \Carbon\Carbon::parse($pending)->lt($nextFrom)) {
                $nextFrom = \Carbon\Carbon::parse($pending);
            }
            DB::table('settlement_feeds')->where('id', $f->id)->update(['page' => $more ? $f->page + 1 : 1, 'scan_started_at' => $more ? $from : null, 'from_at' => $more ? $f->from_at : $nextFrom, 'next_attempt_at' => $more ? now() : now()->addHour(), 'last_error' => null, 'updated_at' => now()]);
        } catch (\Throwable $e) {
            DB::table('settlement_feeds')->where('id', $f->id)->update(['last_error' => 'Settlement feed failed: '.class_basename($e), 'next_attempt_at' => now()->addMinutes(10), 'updated_at' => now()]);
        }
    }
}
