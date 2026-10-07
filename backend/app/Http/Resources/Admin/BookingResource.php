<?php

namespace App\Http\Resources\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BookingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'booking_code' => $this->booking_code,
            'user' => $this->whenLoaded('user', function () {
                return [
                    'id' => $this->user?->id,
                    'name' => $this->user?->name,
                    'email' => $this->user?->email,
                ];
            }),
            'venue' => $this->whenLoaded('venue', function () {
                return [
                    'id' => $this->venue?->id,
                    'name' => $this->venue?->name,
                    'city' => $this->venue?->city,
                ];
            }),
            'status' => $this->status,
            'starts_at' => $this->starts_at,
            'ends_at' => $this->ends_at,
            'quantity' => $this->quantity,
            'subtotal' => (float) $this->subtotal,
            'total_amount' => (float) $this->total_amount,
            'hold_expires_at' => $this->hold_expires_at,
            'confirmed_at' => $this->confirmed_at,
            'cancelled_at' => $this->cancelled_at,
            'notes' => $this->notes,
            'payment' => $this->whenLoaded('payment'),
            'items' => $this->whenLoaded('items'),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
