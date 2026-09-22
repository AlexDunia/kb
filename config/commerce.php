<?php
return [
'currency'=>env('COMMERCE_CURRENCY','NGN'),
'reservation_minutes'=>(int)env('COMMERCE_RESERVATION_MINUTES',20),
'platform_fee_bps'=>(int)env('COMMERCE_PLATFORM_FEE_BPS',0),
'dynamic_splits_enabled'=>filter_var(env('PAYSTACK_DYNAMIC_SPLITS_ENABLED',false),FILTER_VALIDATE_BOOL),
'paystack_fee_bearer'=>env('PAYSTACK_FEE_BEARER','account'),
];