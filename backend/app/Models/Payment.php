<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    protected $fillable = [
        'booking_id',
        'event_ticket_order_id',
        'payment_session_id',
        'payment_code',
        'provider',
        'provider_request_id',
        'provider_transaction_id',
        'method',
        'status',
        'amount',
        'paid_at',
        'failed_at',
        'failure_reason',
        'provider_payload',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'paid_at' => 'datetime',
            'failed_at' => 'datetime',
            'provider_payload' => 'array',
        ];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function eventTicketOrder(): BelongsTo
    {
        return $this->belongsTo(EventTicketOrder::class);
    }

    public function paymentSession(): BelongsTo
    {
        return $this->belongsTo(PaymentSession::class);
    }
}