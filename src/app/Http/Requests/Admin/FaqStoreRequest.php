<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class FaqStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'question' => 'required|string',
            'answer' => 'required|string',

            'englishQuestion' => 'nullable|string',
            'arabicQuestion' => 'nullable|string',
            'urduQuestion' => 'nullable|string',

            'englishAnswer' => 'nullable|string',
            'arabicAnswer' => 'nullable|string',
            'urduAnswer' => 'nullable|string',

            'sortOrder' => 'nullable|integer|min:0|max:1000000',
            'isActive' => 'nullable|boolean',
        ];
    }
}
