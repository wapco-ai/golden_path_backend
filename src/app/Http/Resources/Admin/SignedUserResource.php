<?php

namespace App\Http\Resources\Admin;

use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\User */
class SignedUserResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'mobile' => $this->mobile,
            'username' => $this->username,
            'status' => $this->status,
            'isActive' => (bool)($this->is_active ?? false),
            'createdAt' => optional($this->created_at)->toISOString(),
            'lastLoginAt' => optional($this->last_login_at)->toISOString(),
            'lastLoginIp' => $this->last_login_ip,
        ];
    }
}
