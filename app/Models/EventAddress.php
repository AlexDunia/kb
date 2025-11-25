<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EventAddress extends Model
{
    /** @use HasFactory<\Database\Factories\EventAddressFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'venue_name',
        'address_line1',
        'address_line2',
        'city',
        'state',
        'postal_code',
        'country',
    ];

    /**
     * Get the events for the address.
     */
    public function events(): HasMany
    {
        return $this->hasMany(Event::class, 'address_id');
    }
}
