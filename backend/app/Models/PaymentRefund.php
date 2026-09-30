<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'payment_id',
    'provider',
    'provider_refund_id',
    'reference_id',
    'amount',
    'currency',
    'status',
    'reason',
    'failure_code',
    'failure_reason',
    'refund_fee_amount',
    'provider_payload',
    'requested_at',
    'succeeded_at',
    'failed_at',
])]
class PaymentRefund extends Model
{
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'refund_fee_amount' => 'decimal:2',
            'provider_payload' => 'array',
            'requested_at' => 'datetime',
            'succeeded_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }
}