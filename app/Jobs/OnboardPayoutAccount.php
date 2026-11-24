<?php

namespace App\Jobs;

use App\Models\PayoutAccount;
use App\Services\Commerce\FinancialAudit;
use App\Services\Commerce\PaystackService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

class OnboardPayoutAccount implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 300;

    public int $tries = 1;

    public int $timeout = 45;

    public function __construct(public int $accountId) {}

    public function uniqueId(): string
    {
        return (string) $this->accountId;
    }

    public function handle(PaystackService $provider): void
    {
        $a = PayoutAccount::findOrFail($this->accountId);
        if (! PayoutAccount::whereKey($a->id)->whereIn('onboarding_state', ['queued', 'created'])->where('next_attempt_at', '<=', now())->update(['next_attempt_at' => now()->addMinutes(5), 'attempts' => DB::raw('attempts + 1')])) {
            return;
        }
        $creating = false;
        try {
            if (! $a->provider_subaccount_code) {
                $number = $a->account_number;
                $verified = $provider->request('GET', '/bank/resolve', ['account_number' => $number, 'bank_code' => $a->bank_code])['data'];
                if (($verified['account_number'] ?? null) !== $number || ! is_string($verified['account_name'] ?? null) || strlen($verified['account_name']) > 150) {
                    throw new \RuntimeException('Bank verification mismatch');
                }
                // Persist before the non-idempotent provider creation. An ambiguous crash is held for review, never blindly replayed.
                $a->forceFill(['account_name' => $verified['account_name'], 'verified_at' => now(), 'onboarding_state' => 'creating'])->save();
                $creating = true;
                $data = $provider->request('POST', '/subaccount', ['business_name' => $a->account_name, 'bank_code' => $a->bank_code, 'account_number' => $number, 'percentage_charge' => 0, 'metadata' => json_encode(['payout_account_id' => $a->id], JSON_THROW_ON_ERROR)])['data'];
                if (! is_string($data['subaccount_code'] ?? null) || ($data['account_number'] ?? null) !== $number || ($data['currency'] ?? null) !== 'NGN') {
                    throw new \RuntimeException('Subaccount verification mismatch');
                }
                $a->forceFill(['provider_subaccount_code' => $data['subaccount_code'], 'provider_subaccount_id' => (string) ($data['id'] ?? ''), 'bank_name' => substr((string) ($data['settlement_bank'] ?? ''), 0, 120), 'account_number' => null, 'onboarding_state' => 'created'])->save();
                $creating = false;
            } else {
                $data = $provider->request('GET', '/subaccount/'.rawurlencode($a->provider_subaccount_code))['data'];
            }
            if (($data['subaccount_code'] ?? null) !== $a->provider_subaccount_code || ($data['currency'] ?? null) !== 'NGN' || substr((string) ($data['account_number'] ?? ''), -4) !== $a->account_last4) {
                throw new \RuntimeException('Subaccount identity mismatch');
            }
            $ready = ($data['active'] ?? false) && ($data['is_verified'] ?? false);
            DB::transaction(function () use ($a, $ready) {
                \App\Models\User::whereKey($a->user_id)->lockForUpdate()->firstOrFail();
                if (PayoutAccount::where('user_id', $a->user_id)->where('id', '>', $a->id)->where('status', 'active')->exists()) {
                    $a->forceFill(['status' => 'disabled', 'onboarding_state' => 'superseded', 'next_attempt_at' => null])->save();

                    return;
                }
                if ($ready) {
                    PayoutAccount::where('user_id', $a->user_id)->where('id', '!=', $a->id)->where('status', 'active')->update(['status' => 'disabled']);
                }
                $a->forceFill(['status' => $ready ? 'active' : 'pending', 'onboarding_state' => 'created', 'next_attempt_at' => now()->addHour(), 'last_error' => null])->save();
                FinancialAudit::record('payout_account.provider_checked', ['account_id' => $a->id, 'ready' => $ready], $a->user_id);
            }, 3);
        } catch (\Throwable $e) {
            $a->forceFill(['onboarding_state' => $creating ? 'review' : $a->onboarding_state, 'last_error' => 'Onboarding requires review: '.class_basename($e), 'next_attempt_at' => $creating ? null : now()->addHour()])->save();
        }
    }
}
