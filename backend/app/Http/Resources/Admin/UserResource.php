<?php

namespace App\Http\Resources\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'email_verified_at' => $this->email_verified_at,
            'role_id' => $this->role_id,
            'role' => $this->whenLoaded('role', function () {
                return [
                    'id' => $this->role?->id,
                    'name' => $this->role?->name,
                    'display_name' => $this->role?->display_name,
                    'description' => $this->role?->description,
                    'permissions' => $this->when(
                        $this->relationLoaded('role') && $this->role && $this->role->relationLoaded('permissions'),
                        function () {
                            return $this->role->permissions->map(fn ($p) => [
                                'id' => $p->id,
                                'name' => $p->name,
                                'display_name' => $p->display_name,
                            ]);
                        }
                    ),
                ];
            }),
            'merchant' => $this->whenLoaded('merchant'),
            'organizer' => $this->whenLoaded('organizer'),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
