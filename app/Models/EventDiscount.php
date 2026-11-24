<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EventDiscount extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['ticket_type_ids' => 'array', 'active' => 'boolean', 'starts_at' => 'immutable_datetime', 'ends_at' => 'immutable_datetime', 'value' => 'integer', 'usage_limit' => 'integer', 'version' => 'integer'];
}
