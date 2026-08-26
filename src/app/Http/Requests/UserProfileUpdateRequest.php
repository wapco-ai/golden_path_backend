<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UserProfileUpdateRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'fullName' => 'sometimes|required|string|min:3|max:255',
            'email' => 'sometimes|nullable|email:rfc,dns|max:255',
            'gender' => ['sometimes','nullable', Rule::in(['male','female','other'])],
            'birthDate' => 'sometimes|nullable|date_format:Y-m-d',
            'nationalId' => 'sometimes|nullable|string|min:8|max:20',

            'address' => 'sometimes|nullable|array',
            'address.province' => 'sometimes|nullable|string|max:100',
            'address.city' => 'sometimes|nullable|string|max:100',
            'address.postalCode' => 'sometimes|nullable|string|max:20',
            'address.line1' => 'sometimes|nullable|string|max:500',

            'preferences' => 'sometimes|nullable|array',
            'preferences.language' => 'sometimes|nullable|string|max:10',
            'preferences.notifications' => 'sometimes|nullable|array',
            'preferences.notifications.sms' => 'sometimes|boolean',
            'preferences.notifications.push' => 'sometimes|boolean',
            'preferences.notifications.email' => 'sometimes|boolean',

            'avatarUrl' => 'sometimes|nullable|url|max:2048',
        ];
    }
}
