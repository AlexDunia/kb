<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TicketType extends Model
{
    /** @use HasFactory<\Database\Factories\TicketTypeFactory> */
    use HasFactory;

    protected $fillable = [
        'event_id',
        'name',
        'unit_type',
        'color',
        'price',
        'quantity',
        'people_per_unit',
        'max_per_person',
        'visible',
        'description',
        'sales_start_date',
        'sales_end_date',
        'is_featured',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'quantity' => 'integer',
        'people_per_unit' => 'integer',
        'max_per_person' => 'integer',
        'visible' => 'boolean',
        'sales_start_date' => 'datetime',
        'sales_end_date' => 'datetime',
        'is_featured' => 'boolean',
    ];

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }
}