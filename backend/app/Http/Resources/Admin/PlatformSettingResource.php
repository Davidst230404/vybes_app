<?php

namespace App\Http\Resources\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PlatformSettingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'key' => $this->key,
            'value' => match ($this->type) {
                'integer' => (int) $this->value,
                'boolean' => filter_var($this->value, FILTER_VALIDATE_BOOLEAN),
                'json' => json_decode($this->value, true),
                default => $this->value,
            },
            'type' => $this->type,
            'description' => $this->description,
            'updated_at' => $this->updated_at,
        ];
    }
}
