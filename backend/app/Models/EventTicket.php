<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'event_ticket_order_id',
    'event_id',
    'ticket_code',
    'status',
    'qr_payload',
    'issued_at',
    'checked_in_at',
])]
class EventTicket extends Model
{
    public function order(): BelongsTo
    {
        return $this->belongsTo(
            EventTicketOrder::class,
            'event_ticket_order_id'
        );
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    protected function casts(): array
    {
        return [
            'issued_at' => 'datetime',
            'checked_in_at' => 'datetime',
        ];
    }
}