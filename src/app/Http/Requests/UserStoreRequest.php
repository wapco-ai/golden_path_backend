<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class UserStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Employer verification is the only account-creation path in Razavi
        // mode. Reject legacy pre-registration before validation or any writes.
        return config('public_auth.provider') === 'local';
    }

    protected function failedAuthorization(): void
    {
        throw new HttpResponseException(response()->json([
            'code' => 'REGISTRATION_DISABLED',
            'message' => 'ثبت حساب فقط پس از تأیید شماره موبایل انجام می‌شود.',
            'errors' => (object) [],
        ], 403));
    }

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
