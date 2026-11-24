<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayoutAccount extends Model
{
    const STATUS_PENDING = 'pending';

    const STATUS_ACTIVE = 'active';

    const STATUS_DISABLED = 'disabled';

    protected $guarded = ['id'];

    protected $hidden = ['account_number', 'fingerprint', 'provider_subaccount_code', 'provider_subaccount_id'];

    protected $casts = ['account_number' => 'encrypted', 'verified_at' => 'immutable_datetime', 'next_attempt_at' => 'immutable_datetime'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
