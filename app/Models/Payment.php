<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    public const STATUS_CREATED = 'created';

    public const STATUS_INITIALIZED = 'initialized';

    public const STATUS_SUCCESS = 'success';

    public const STATUS_FAILED = 'failed';

    public const STATUS_REVIEW = 'review';

    protected $fillable = ['order_id', 'provider', 'reference', 'provider_transaction_id', 'amount_minor', 'currency', 'status', 'access_code', 'authorization_url', 'channel', 'gateway_response', 'paid_at', 'verified_at'];

    protected $casts = ['amount_minor' => 'integer', 'provider_fee_minor' => 'integer', 'refunded_minor' => 'integer', 'paid_at' => 'datetime', 'verified_at' => 'datetime'];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
