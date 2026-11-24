<?php

namespace App\Services\Commerce;

use App\Models\Order;

class PaystackSplitService
{
    public function build(Order $o): ?array
    {
        if (! config('commerce.dynamic_splits_enabled')) {
            return null;
        }if (config('commerce.paystack_fee_bearer', 'account') !== 'account') {
            throw new \RuntimeException('Only platform-borne provider fees are supported.');
        }$o->loadMissing('allocations');
        $a = $o->allocations->filter(fn ($x) => $x->seller_net_minor > 0);
        if ($a->isEmpty() || $a->contains(fn ($x) => empty($x->provider_subaccount_code))) {
            return null;
        }

        return ['type' => 'flat', 'bearer_type' => config('commerce.paystack_fee_bearer', 'account'), 'subaccounts' => $a->map(fn ($x) => ['subaccount' => $x->provider_subaccount_code, 'share' => (int) $x->seller_net_minor])->values()->all()];
    }
}
