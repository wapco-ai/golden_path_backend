<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    private bool $profileCompleted;

    public function __construct($resource, bool $profileCompleted = false)
    {
        parent::__construct($resource);
        $this->profileCompleted = $profileCompleted;
    }

    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'phone' => $this->mobile,
            'fullName' => $this->name,
            'email' => $this->email,
            'nationalId' => $this->national_id,
            'gender' => $this->gender,
            'birthDate' => optional($this->birth_date)->format('Y-m-d'),
            'address' => $this->address ?? (object)[],
            'preferences' => $this->preferences ?? (object)[],
            'avatarUrl' => $this->avatar_url,
            'profileCompleted' => $this->profileCompleted,
            'roles' => [], // اگر بعداً RBAC عمومی اضافه شد
            'level' => null,
        ];
    }
}
