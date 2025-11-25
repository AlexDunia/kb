<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class EventOption extends Model
{
    /** @use HasFactory<\Database\Factories\EventOptionFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'is_custom',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'is_custom' => 'boolean',
    ];

    /**
     * The events that belong to the option.
     */
    public function events(): BelongsToMany
    {
        return $this->belongsToMany(Event::class);
    }
}
