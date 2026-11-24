<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Attendee extends Model
{
    protected $guarded = [];

    protected $casts = ['answers' => 'array', 'inside' => 'boolean', 'entries' => 'integer', 'lock_version' => 'integer'];

    public function ticket()
    {
        return $this->belongsTo(Ticket::class);
    }

    public function orderItem()
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function event()
    {
        return $this->belongsTo(Event::class);
    }
}
