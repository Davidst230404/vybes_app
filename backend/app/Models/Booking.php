<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Booking extends Model
{
    protected $fillable = [
        'booking_code',
        'user_id',
        'venue_id',
        'status',
        'starts_at',
        'ends_at',
        'quantity',
        'subtotal',
        'total_amount',
        'hold_expires_at',
        'confirmed_at',
        'cancelled_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'hold_expires_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'quantity' => 'integer',
            'subtotal' => 'decimal:2',
            'total_amount' => 'decimal:2',
        ];
    }

    /**
     * Booking dibuat oleh User.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Booking dilakukan pada Venue.
     */
    public function venue(): BelongsTo
    {
        return $this->belongsTo(Venue::class);
    }

    /**
     * Booking memiliki satu atau lebih Booking Item.
     */
    public function items(): HasMany
    {
        return $this->hasMany(BookingItem::class);
    }

    /**
     * Booking memiliki satu Payment Session.
     */
    public function paymentSession(): HasOne
    {
        return $this->hasOne(PaymentSession::class);
    }

    /**
     * Booking memiliki satu Payment.
     */
    public function payment(): HasOne
    {
        return $this->hasOne(Payment::class);
    }

    /**
     * Booking memiliki satu Digital Ticket.
     */
    public function ticket(): HasOne
    {
        return $this->hasOne(BookingTicket::class);
    }
}