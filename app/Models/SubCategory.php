<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class SubCategory extends Model
{
    /** @use HasFactory<\Database\Factories\SubCategoryFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
    ];

    /**
     * The events that belong to the subcategory.
     */
    public function events(): BelongsToMany
    {
        return $this->belongsToMany(Event::class);
    }
}
