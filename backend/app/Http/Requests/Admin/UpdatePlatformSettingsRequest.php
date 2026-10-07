<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePlatformSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'booking_hold_duration_minutes' => ['sometimes', 'required', 'integer', 'min:1', 'max:1440'],
        ];
    }
}
