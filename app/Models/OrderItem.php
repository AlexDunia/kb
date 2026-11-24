<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OrderItem extends Model
{
    protected $fillable = [
        'order_id',
        'event_id',
        'ticket_type_id',
        'seller_user_id',
        'source_code',
        'attributed_event_view_id',
        'event_title',
        'ticket_name',
        'unit_type',
        'people_per_unit',
        'unit_price_minor',
        'quantity',
        'line_total_minor',
        'currency',
        'registration_schema',
        'discount_minor',
        'platform_fee_minor',
        'provider_fee_minor',
        'refunded_minor',
        'refunded_quantity',
    ];

    protected $casts = [
        'registration_schema' => 'array',
        'discount_minor' => 'integer',
        'platform_fee_minor' => 'integer',
        'provider_fee_minor' => 'integer',
        'refunded_minor' => 'integer',
        'refunded_quantity' => 'integer',
        'people_per_unit' => 'integer',
        'unit_price_minor' => 'integer',
        'quantity' => 'integer',
        'line_total_minor' => 'integer',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function ticketType(): BelongsTo
    {
        return $this->belongsTo(TicketType::class);
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seller_user_id');
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }

    public function attributedView(): BelongsTo
    {
        return $this->belongsTo(
            EventTrafficView::class,
            'attributed_event_view_id'
        );
    }

    public function attendees(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Attendee::class, 'order_item_id');
    }
}
