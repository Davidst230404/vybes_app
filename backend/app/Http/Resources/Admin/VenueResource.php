<?php

namespace App\Http\Resources\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VenueResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'merchant_id' => $this->merchant_id,
            'merchant' => $this->whenLoaded('merchant', function () {
                return [
                    'id' => $this->merchant?->id,
                    'business_name' => $this->merchant?->business_name,
                    'status' => $this->merchant?->status,
                ];
            }),
            'category_id' => $this->category_id,
            'category' => $this->whenLoaded('category', function () {
                return [
                    'id' => $this->category?->id,
                    'name' => $this->category?->name,
                    'slug' => $this->category?->slug,
                ];
            }),
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'phone' => $this->phone,
            'address' => $this->address,
            'city' => $this->city,
            'province' => $this->province,
            'cover_image' => $this->cover_image,
            'status' => $this->status,
            'resources' => $this->whenLoaded('resources'),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
