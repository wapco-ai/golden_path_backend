<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UserStoreRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'phone' => 'required|string|max:32',

            // Canonical name fields for new clients; fullName remains supported for legacy clients.
            'firstName' => 'nullable|required_with:lastName|string|min:1|max:100',
            'lastName' => 'nullable|required_with:firstName|string|min:1|max:100',
            'fullName' => 'nullable|string|min:3|max:255',

            // بعد از OTP ممکن است نیاید؛ اگر آمد، email معتبر باشد (بدون dns)
            'email' => 'nullable|email:rfc|max:255',

            'nationalId' => 'nullable|string|min:8|max:20',
            'referralCode' => 'nullable|string|max:64',
            'password' => 'nullable|string|min:8|max:255',
        ];
    }
}
