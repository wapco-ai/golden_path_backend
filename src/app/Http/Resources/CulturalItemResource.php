<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CulturalItemResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'title'       => $this->title,
            // اگر در contents ستون address داری، همین را استفاده کن:
            // 'address'  => $this->address,
            // فعلاً null یا بعداً از join با poi_points پر می‌کنی:
            'address'     => null,

            'description' => $this->body,
            'status'      => $this->status,
            'createdAt'   => optional($this->created_at)->toIso8601String(),

            // اگر فرانت الان نیاز ندارد، media را نمی‌فرستیم
            // 'media'    => $this->media,
        ];
    }
}
