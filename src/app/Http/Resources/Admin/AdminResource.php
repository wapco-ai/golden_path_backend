<?php

namespace App\Http\Resources\Admin;

use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\User */
class AdminResource extends JsonResource
{
    public function toArray($request): array
    {
        [$firstName, $lastName] = $this->splitName($this->name);

        $roles = $this->relationLoaded('adminRoles')
            ? $this->adminRoles->pluck('title')->values()->all()
            : [];

        return [
            'id' => $this->id,
            'firstName' => $firstName,
            'lastName' => $lastName,
            'username' => $this->username,
            'email' => $this->email,
            'roles' => $roles,
            'avatar' => $this->avatar_url ?? null,
            // UI wants a display string (e.g. "۱۴۰۴ مرداد")
            'createdAt' => $this->createdAtDisplay($this->created_at),
            'status' => ((bool)($this->is_active ?? false)) ? 'active' : 'inactive',
        ];
    }

    private function splitName(?string $fullName): array
    {
        $fullName = trim((string)$fullName);
        if ($fullName === '') {
            return ['', ''];
        }
        $parts = preg_split('/\s+/u', $fullName) ?: [];
        $first = array_shift($parts) ?? '';
        $last  = trim(implode(' ', $parts));
        return [$first, $last];
    }

    private function createdAtDisplay($createdAt): string
    {
        if (!$createdAt) {
            return '';
        }

        // Prefer Jalali if the package exists.
        if (class_exists('Morilog\\Jalali\\Jalalian')) {
            /** @var \Morilog\Jalali\Jalalian $j */
            $j = \Morilog\Jalali\Jalalian::fromDateTime($createdAt);
            $months = [
                1 => 'فروردین',
                2 => 'اردیبهشت',
                3 => 'خرداد',
                4 => 'تیر',
                5 => 'مرداد',
                6 => 'شهریور',
                7 => 'مهر',
                8 => 'آبان',
                9 => 'آذر',
                10 => 'دی',
                11 => 'بهمن',
                12 => 'اسفند',
            ];
            $monthName = $months[(int)$j->getMonth()] ?? '';
            return $j->getYear() . ' ' . $monthName;
        }

        // Fallback (Gregorian): "YYYY-MM"
        return $createdAt->format('Y-m');
    }
}
