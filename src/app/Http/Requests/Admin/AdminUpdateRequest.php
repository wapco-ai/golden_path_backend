<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class AdminUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        // route param ممکن است id یا admin باشد، در پروژه شما معمولاً :id است
        $id = (int) $this->route('id');

        return [
            'roles' => ['sometimes', 'array', 'min:1'],
            'roles.*' => ['string'],

            'email' => ['sometimes', 'email', 'max:255', 'unique:users,email,' . $id],
            'password' => ['sometimes', 'string', 'min:6', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'email.unique' => 'ایمیل تکراری است.',
            'email.email' => 'فرمت ایمیل صحیح نیست.',
            'password.min' => 'رمز عبور باید حداقل 6 کاراکتر باشد.',
        ];
    }
}
