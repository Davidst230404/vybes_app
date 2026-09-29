<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Venue extends Model
{
    protected $fillable = [
        'merchant_id',
        'category_id',
        'name',
        'slug',
        'description',
        'phone',
        'address',
        'city',
        'province',
        'latitude',
        'longitude',
        'cover_image',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
        ];
    }

    /**
     * Venue dimiliki oleh Merchant.
     */
    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    /**
     * Venue memiliki satu Category.
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * Venue memiliki banyak Resource.
     */
    public function resources(): HasMany
    {
        return $this->hasMany(Resource::class);
    }

    /**
     * Venue dapat digunakan untuk banyak Event.
     */
    public function events(): HasMany
    {
        return $this->hasMany(Event::class);
    }
}