<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

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
            'startDate' => 'sometimes|nullable|date_format:Y-m-d',
            'endDate' => 'sometimes|nullable|date_format:Y-m-d',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (!$validator->errors()->has('startDate') && !$validator->errors()->has('endDate')
                && is_string($this->input('startDate')) && is_string($this->input('endDate'))
                && $this->input('startDate') > $this->input('endDate')) {
                $validator->errors()->add('endDate', 'تاریخ پایان نباید پیش از تاریخ شروع باشد.');
            }
        });
    }

    public function messages(): array
    {
        return [
            'startDate.date_format' => 'تاریخ شروع باید یک تاریخ میلادی معتبر با قالب YYYY-MM-DD باشد.',
            'endDate.date_format' => 'تاریخ پایان باید یک تاریخ میلادی معتبر با قالب YYYY-MM-DD باشد.',
        ];
    }
}
