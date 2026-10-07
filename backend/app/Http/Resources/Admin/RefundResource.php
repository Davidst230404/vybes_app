<?php

namespace App\Http\Resources\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RefundResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'payment_id' => $this->payment_id,
            'provider' => $this->provider,
            'provider_refund_id' => $this->provider_refund_id,
            'reference_id' => $this->reference_id,
            'amount' => (float) $this->amount,
            'currency' => $this->currency,
            'status' => $this->status,
            'reason' => $this->reason,
            'failure_code' => $this->failure_code,
            'failure_reason' => $this->failure_reason,
            'refund_fee_amount' => $this->refund_fee_amount !== null ? (float) $this->refund_fee_amount : null,
            'requested_at' => $this->requested_at,
            'succeeded_at' => $this->succeeded_at,
            'failed_at' => $this->failed_at,
            'payment' => $this->whenLoaded('payment', function () {
                return [
                    'id' => $this->payment?->id,
                    'reference_id' => $this->payment?->reference_id,
                    'amount' => (float) $this->payment?->amount,
                    'status' => $this->payment?->status,
                    'payment_method' => $this->payment?->payment_method,
                    'booking_id' => $this->payment?->booking_id,
                    'event_ticket_order_id' => $this->payment?->event_ticket_order_id,
                ];
            }),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
