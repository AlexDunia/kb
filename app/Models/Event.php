<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Event extends Model
{
    /** @use HasFactory<\Database\Factories\EventFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'title',
        'description',
        'category_id',
        'organizer_id',
        'address_id',
        'date',
        'price',
        'total_tickets',
        'duration',
        'featured',
        'main_image',
        'banner_image',
        'created_by',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'date' => 'datetime',
        'ends_at' => 'datetime',
        'lock_version' => 'integer',
        'allow_reentry' => 'boolean',
        'booking_cutoff_at' => 'immutable_datetime',
        'cancelled_at' => 'immutable_datetime',
        'completed_at' => 'immutable_datetime',
        'archived_at' => 'immutable_datetime',
        'price' => 'decimal:2',
        'featured' => 'boolean',
    ];

    /**
     * Get the category that owns the event.
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * Get the organizer that owns the event.
     */
    public function organizer(): BelongsTo
    {
        return $this->belongsTo(Organizer::class);
    }

    /**
     * Get the address that owns the event.
     */
    public function address(): BelongsTo
    {
        return $this->belongsTo(EventAddress::class, 'address_id');
    }

    /**
     * Get the user that created the event.
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get the ticket types for the event.
     */
    public function ticketTypes(): HasMany
    {
        return $this->hasMany(TicketType::class);
    }

    /**
     * Get the FAQs for the event.
     */
    public function faqs(): HasMany
    {
        return $this->hasMany(Faq::class);
    }

    public function media(): HasMany
    {
        return $this->hasMany(EventMedia::class);
    }

    /**
     * The subcategories that belong to the event.
     */
    public function subCategories(): BelongsToMany
    {
        return $this->belongsToMany(SubCategory::class);
    }

    /**
     * The options that belong to the event.
     */
    public function eventOptions(): BelongsToMany
    {
        return $this->belongsToMany(EventOption::class);
    }

    public function scopePubliclyVisible(Builder $query): Builder
    {
        return $query->where('events.status', 'active')->where('events.lifecycle_state', '!=', 'archived');
    }

    public function publicProfile(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(EventPublicProfile::class);
    }

    public function attendees(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Attendee::class, 'event_id');
    }

    public function bookingsOpen(): bool
    {
        return $this->status === 'active' && ($this->lifecycle_state ?? 'open') === 'open'
            && (! $this->booking_cutoff_at || $this->booking_cutoff_at->isFuture())
            && (! $this->ends_at || $this->ends_at->isFuture());
    }

    public function itemsExist(): bool
    {
        return \App\Models\OrderItem::where('event_id', $this->id)->exists() || \App\Models\Purchase::where('event_id', $this->id)->exists();
    }
}
