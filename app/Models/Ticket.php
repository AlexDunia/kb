<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Ticket extends Model
{
    protected $hidden = ['token', 'token_hash'];

    protected $fillable = ['unit_index', 'public_id', 'order_item_id', 'event_id', 'ticket_type_id', 'token', 'token_hash', 'admit_count', 'status', 'issued_at'];

    protected $casts = ['token' => 'encrypted', 'admit_count' => 'integer', 'issued_at' => 'datetime'];

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function ticketType(): BelongsTo
    {
        return $this->belongsTo(TicketType::class);
    }

    public function attendees()
    {
        return $this->hasMany(Attendee::class);
    }
}
