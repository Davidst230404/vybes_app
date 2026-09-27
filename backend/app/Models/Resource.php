<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Resource extends Model
{
    protected $fillable = [
        'venue_id',
        'name',
        'slug',
        'description',
        'capacity',
        'type',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'capacity' => 'integer',
        ];
    }

    /**
     * Resource dimiliki oleh Venue.
     */
    public function venue(): BelongsTo
    {
        return $this->belongsTo(Venue::class);
    }

    /**
     * Resource memiliki banyak Schedule.
     */
    public function schedules(): HasMany
    {
        return $this->hasMany(Schedule::class);
    }
}