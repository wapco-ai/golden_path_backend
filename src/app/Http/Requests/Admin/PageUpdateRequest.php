<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class PageUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'phones' => 'nullable|array|max:3',
            'phones.*' => 'nullable|string|max:100',

            'emails' => 'nullable|array|max:3',
            'emails.*' => 'nullable|email:rfc,dns|max:150',

            'description' => 'nullable|string',
            'englishDescription' => 'nullable|string',
            'arabicDescription' => 'nullable|string',
            'urduDescription' => 'nullable|string',

            'address' => 'nullable|string',
            'englishAddress' => 'nullable|string',
            'arabicAddress' => 'nullable|string',
            'urduAddress' => 'nullable|string',
        ];
    }
}
