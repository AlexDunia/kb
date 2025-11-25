<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TicketType extends Model
{
    /** @use HasFactory<\Database\Factories\TicketTypeFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'event_id',
        'name',
        'price',
        'quantity',
        'description',
        'sales_end_date',
        'is_featured',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'price' => 'decimal:2',
        'quantity' => 'integer',
        'sales_end_date' => 'date',
        'is_featured' => 'boolean',
    ];

    /**
     * Get the event that owns the ticket type.
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }
}
