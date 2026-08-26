<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class TempBlockAreaUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'floor' => ['sometimes', 'integer'],
            'restrict_type' => ['sometimes', 'in:close,penalty'],

            'valid_from' => ['sometimes', 'date'],
            'valid_to'   => ['nullable', 'date'],

            'reason' => ['nullable', 'string', 'max:2000'],

            'geom_wkt_32640'     => ['sometimes', 'string'],
            'geom_geojson_4326'  => ['sometimes', 'array'],
        ];
    }

    public function withValidator($validator)
    {
        $validator->after(function ($v) {
            $data = $this->all();

            if (isset($data['valid_from'], $data['valid_to']) && $data['valid_to'] !== null) {
                if (strtotime($data['valid_to']) < strtotime($data['valid_from'])) {
                    $v->errors()->add('valid_to', 'valid_to must be >= valid_from.');
                }
            }
        });
    }
}
