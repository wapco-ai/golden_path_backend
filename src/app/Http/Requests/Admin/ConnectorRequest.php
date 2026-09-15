<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class ConnectorRequest extends FormRequest
{
    public function authorize(): bool { return true; } // Routes use AdminAuth.

    public function rules(): array
    {
        return [
            'version' => 'nullable|integer|min:1',
            'kind' => 'required|in:stair,ramp,elevator,escalator',
            'direction' => 'required|in:both,forward,reverse',
            'wait_seconds' => 'nullable|numeric|min:0|max:3600',
            'info' => 'required|array',
            'info.basic_info.title.fa' => 'required|string|max:250',
            'info.operational.status' => 'required|in:active,inactive',
            'info.operational.transport_modes' => 'required|array|min:1',
            'info.operational.transport_modes.*' => 'required|in:walk,wheelchair',
            'info.operational.gender_access' => 'required|array|min:1',
            'info.operational.gender_access.*' => 'required|in:male,female,both,family',
            'stops' => 'required|array|min:2|max:32',
            'stops.*.floor' => 'required|integer|distinct|exists:routing_floors,floor',
            'stops.*.access_id' => 'nullable|integer|distinct',
            'stops.*.area_id' => 'nullable|integer',
            'stops.*.lat' => 'nullable|numeric|between:-90,90',
            'stops.*.lon' => 'nullable|numeric|between:-180,180',
            'stops.*.travel_seconds' => 'nullable|numeric|gt:0|max:3600',
            'stops.*.reverse_seconds' => 'nullable|numeric|gt:0|max:3600',
        ];
    }
}
