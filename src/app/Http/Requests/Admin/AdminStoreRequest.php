<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class AdminStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'firstName' => ['required', 'string', 'max:120'],
            'lastName'  => ['required', 'string', 'max:120'],
            'username'  => ['required', 'string', 'max:80'],
            'roles'     => ['required', 'array', 'min:1'],
            'roles.*'   => ['string', 'max:120'],
        ];
    }
}
