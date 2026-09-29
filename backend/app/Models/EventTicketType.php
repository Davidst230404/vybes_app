<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'event_id',
    'name',
    'description',
    'price',
    'quota',
    'sold',
    'reserved',
    'sales_starts_at',
    'sales_ends_at',
    'status',
])]
class EventTicketType extends Model
{
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(
            EventTicketOrder::class,
            'event_ticket_type_id'
        );
    }

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'quota' => 'integer',
            'sold' => 'integer',
            'reserved' => 'integer',
            'sales_starts_at' => 'datetime',
            'sales_ends_at' => 'datetime',
        ];
    }
}