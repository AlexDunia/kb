<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PayoutAccount;
use App\Models\User;
use App\Services\Commerce\FinancialAudit;
use App\Services\Commerce\PaystackService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class PayoutAccountController extends Controller
{
    public function banks(Request $r, PaystackService $provider)
    {
        $v = $r->validate(['page' => 'sometimes|integer|min:1|max:100']);
        $page = $v['page'] ?? 1;

        return response()->json(Cache::remember('finance.banks.ngn.'.$page, 3600, fn () => $provider->request('GET', '/bank', ['country' => 'nigeria', 'currency' => 'NGN', 'perPage' => 100, 'page' => $page])));
    }

    public function index(Request $r)
    {
        $v = $r->validate(['page' => 'sometimes|integer|min:1']);

        return response()->json(PayoutAccount::where('user_id', $r->user()->id)->orderByDesc('id')->paginate(20)->through(fn ($a) => $this->payload($a)));
    }

    public function store(Request $r)
    {
        $v = $r->validate(['bank_code' => 'required|string|regex:/^[0-9]{3,9}$/', 'account_number' => 'required|string|regex:/^[0-9]{10}$/']);
        abort_unless(strtoupper(config('commerce.currency', 'NGN')) === 'NGN', 422, 'Bank onboarding currently supports Nigerian NGN accounts.');
        $fingerprint = hash_hmac('sha256', $v['bank_code'].':'.$v['account_number'], config('app.key'));
        $a = DB::transaction(function () use ($r, $v, $fingerprint) {
            User::whereKey($r->user()->id)->lockForUpdate()->firstOrFail();
            $existing = PayoutAccount::where('user_id', $r->user()->id)->where('fingerprint', $fingerprint)->first();
            if ($existing) {
                return $existing;
            }
            $a = PayoutAccount::create(['user_id' => $r->user()->id, 'provider' => 'paystack', 'status' => 'pending', 'bank_code' => $v['bank_code'], 'account_number' => $v['account_number'], 'account_last4' => substr($v['account_number'], -4), 'fingerprint' => $fingerprint, 'onboarding_state' => 'queued', 'next_attempt_at' => now()]);
            FinancialAudit::record('payout_account.requested', ['account_id' => $a->id, 'last4' => $a->account_last4], $r->user()->id);

            return $a;
        }, 3);

        return response()->json(['data' => $this->payload($a)], 202);
    }

    private function payload(PayoutAccount $a): array
    {
        return $a->only(['id', 'status', 'onboarding_state', 'account_name', 'bank_name', 'account_last4', 'verified_at', 'last_error', 'created_at']);
    }
}
