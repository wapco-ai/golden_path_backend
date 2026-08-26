<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class TempBlockAreaStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        // فعلا auth ندارید => true
        return true;
    }

    public function rules(): array
    {
        return [
            'floor' => ['required', 'integer'],

            // در DB text است ولی در API محدودش می‌کنیم
            'restrict_type' => ['sometimes', 'in:close,penalty'],

            'valid_from' => ['sometimes', 'date'],
            'valid_to'   => ['nullable', 'date'],

            'reason' => ['nullable', 'string', 'max:2000'],

            // geom: دقیقا یکی کافیست (custom validation در withValidator)
            'geom_wkt_32640'     => ['sometimes', 'string'],
            'geom_geojson_4326'  => ['sometimes', 'array'],

            // موقت/اختیاری تا auth بیاد
            'created_by' => ['sometimes', 'nullable', 'integer'],
        ];
    }

    public function withValidator($validator)
    {
        $validator->after(function ($v) {
            $data = $this->all();
            $hasWkt = array_key_exists('geom_wkt_32640', $data);
            $hasGeo = array_key_exists('geom_geojson_4326', $data);

            if (!$hasWkt && !$hasGeo) {
                $v->errors()->add('geom', 'Either geom_wkt_32640 or geom_geojson_4326 is required.');
            }

            if (isset($data['valid_from'], $data['valid_to']) && $data['valid_to'] !== null) {
                if (strtotime($data['valid_to']) < strtotime($data['valid_from'])) {
                    $v->errors()->add('valid_to', 'valid_to must be >= valid_from.');
                }
            }
        });
    }
}
