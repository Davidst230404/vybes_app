<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BookingTicket extends Model
{
    protected $fillable = [
        'booking_id',
        'ticket_code',
        'status',
        'qr_payload',
        'issued_at',
        'checked_in_at',
    ];

    protected function casts(): array
    {
        return [
            'issued_at' => 'datetime',
            'checked_in_at' => 'datetime',
        ];
    }

    /**
     * Ticket dimiliki oleh satu Booking.
     */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }
}