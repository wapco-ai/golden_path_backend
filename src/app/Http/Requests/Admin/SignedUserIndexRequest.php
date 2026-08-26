<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class SignedUserIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Access is already protected by AdminAuth middleware
        return true;
    }

    public function rules(): array
    {
        return [
            'page' => 'sometimes|integer|min:1',
            'pageSize' => 'sometimes|integer|min:1|max:100',
            'search' => 'sometimes|nullable|string|max:255',
        ];
    }
}
