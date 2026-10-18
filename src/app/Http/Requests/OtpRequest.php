<?php

namespace App\Http\Requests;

use App\Support\IranianMobile;
use Illuminate\Foundation\Http\FormRequest;

class OtpRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    protected function prepareForValidation(): void
    {
        if (config('public_auth.provider') === 'razavi' && is_string($this->input('phone'))) {
            $this->merge(['phone' => IranianMobile::normalize($this->input('phone')) ?? $this->input('phone')]);
        }
    }

    public function rules(): array
    {
        return [
            'phone' => config('public_auth.provider') === 'razavi'
                ? ['required', 'string', 'regex:/^09[0-9]{9}$/D']
                : 'required|string|max:32',
        ];
    }
}
