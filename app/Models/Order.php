<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    public const STATUS_PENDING_PAYMENT = 'pending_payment';
    public const STATUS_PAID = 'paid';
    public const STATUS_PAYMENT_FAILED = 'payment_failed';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_PAYMENT_REVIEW = 'payment_review';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_REFUNDED = 'refunded';

    protected $fillable = [
        'public_id',
        'checkout_token_hash',
        'visitor_hash',
        'cart_fingerprint',
        'user_id',
        'customer_first_name',
        'customer_last_name',
        'customer_email',
        'customer_phone',
        'currency',
        'subtotal_minor',
        'discount_minor',
        'total_minor',
        'status',
        'expires_at',
        'paid_at',
    ];

    protected $casts = [
        'subtotal_minor' => 'integer',
        'discount_minor' => 'integer',
        'total_minor' => 'integer',
        'expires_at' => 'datetime',
        'paid_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(OrderAllocation::class);
    }

    public function isReservationActive(): bool
    {
        return $this->status === self::STATUS_PENDING_PAYMENT
            && $this->expires_at?->isFuture();
    }

    public function isPaid(): bool
    {
        return $this->status === self::STATUS_PAID;
    }
}
