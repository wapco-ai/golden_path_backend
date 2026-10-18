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
            // Year zero is unsupported by PostgreSQL. Tehran midnight on the
            // first day of AD 1 also converts to UTC year zero; keep both bounds
            // at the same earliest representable Tehran calendar date.
            'startDate' => 'bail|sometimes|nullable|date_format:Y-m-d|after_or_equal:0001-01-02',
            'endDate' => 'bail|sometimes|nullable|date_format:Y-m-d|after_or_equal:0001-01-02',
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
            'startDate.after_or_equal' => 'تاریخ شروع باید 0001-01-02 میلادی یا بعد از آن باشد.',
            'endDate.after_or_equal' => 'تاریخ پایان باید 0001-01-02 میلادی یا بعد از آن باشد.',
        ];
    }
}
