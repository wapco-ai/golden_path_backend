<?php

namespace App\Http\Requests;

use App\Support\IranianMobile;
use Illuminate\Foundation\Http\FormRequest;

class OtpVerifyRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    protected function prepareForValidation(): void
    {
        if (config('public_auth.provider') !== 'razavi') return;
        if (is_string($this->input('phone'))) {
            $this->merge(['phone' => IranianMobile::normalize($this->input('phone')) ?? $this->input('phone')]);
        }
        if (is_string($this->input('otp'))) {
            $this->merge(['otp' => IranianMobile::digits(trim($this->input('otp')))]);
        }
    }

    public function rules(): array
    {
        if (config('public_auth.provider') === 'razavi') {
            $length = max(4, min(8, (int) config('public_auth.otp.length', 4)));

            return [
                'phone' => ['required', 'string', 'regex:/^09[0-9]{9}$/D'],
                'otp' => ['required', 'string', 'regex:/^[0-9]{'.$length.'}$/D'],
            ];
        }

        return [
            'phone' => 'required|string|max:32',
            'otp'   => 'required|string|min:1|max:10',
        ];
    }
}
