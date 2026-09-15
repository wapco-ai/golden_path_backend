<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreLocationShareRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'recipientPhone' => ['required', 'string', 'max:32'],
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
            'floor' => ['required', 'integer', Rule::in([-1, 0])],
            'accuracyM' => ['nullable', 'numeric', 'min:0', 'max:5000'],
        ];
    }
}
