<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class SameFloorStairRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'version' => 'nullable|integer|min:1',
            'kind' => 'required|in:same_floor_stair',
            'stair_area_id' => 'required|integer|min:1',
            'direction' => 'required|in:both,forward,reverse',
            'info' => 'required|array',
            'info.basic_info.title.fa' => 'required|string|max:250',
            'info.basic_info.title.*' => 'nullable|string|max:250',
            'info.basic_info.description' => 'nullable|string|max:10000',
            'info.grouping' => 'nullable|array:group_id,sub_group_id,sub_group_label',
            'info.operational.status' => 'required|in:active,inactive',
            'info.operational.gender_access' => 'required|array|min:1',
            'info.operational.gender_access.*' => 'required|in:male,female,both,family|distinct',
            'info.operational.transport_modes' => 'required|array|size:1',
            'info.operational.transport_modes.*' => 'required|in:walk',
            'info.operational.is_covered' => 'nullable|boolean',
            'info.notes' => 'nullable|string|max:10000',
            'stops' => 'required|array|list|size:2',
            'stops.*.floor' => 'required|integer|exists:routing_floors,floor',
            'stops.*.access_id' => 'required|integer|min:1|distinct',
            'stops.*.area_id' => 'required|integer|min:1',
            'stops.*.travel_seconds' => 'nullable|numeric|gt:0|max:3600',
            'stops.*.reverse_seconds' => 'nullable|numeric|gt:0|max:3600',
        ];
    }

    public function messages(): array
    {
        return [
            'stops.size' => 'پاگرد پایین و پاگرد بالا را مشخص کنید.',
            'stops.*.access_id.distinct' => 'برای دو پاگرد دو نقطهٔ دسترسی متفاوت انتخاب کنید.',
            'info.operational.transport_modes.*.in' => 'عبور از پلهٔ داخل طبقه فقط برای پیاده مجاز است.',
        ];
    }
}
