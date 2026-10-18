<?php

namespace App\Support;

class IranianMobile
{
    public static function digits(string $value): string
    {
        return strtr($value, array_combine(
            preg_split('//u', '۰۱۲۳۴۵۶۷۸۹٠١٢٣٤٥٦٧٨٩', -1, PREG_SPLIT_NO_EMPTY),
            str_split('01234567890123456789'),
        ));
    }

    public static function normalize(string $value): ?string
    {
        $value = preg_replace('/[\s()\-]+/u', '', self::digits(trim($value)));
        foreach (['0098', '+98', '98'] as $prefix) {
            if (str_starts_with($value, $prefix)) {
                $value = '0'.substr($value, strlen($prefix));
                break;
            }
        }
        if (preg_match('/^9[0-9]{9}$/D', $value)) {
            $value = '0'.$value;
        }

        return preg_match('/^09[0-9]{9}$/D', $value) ? $value : null;
    }

    public static function international(string $mobile): string
    {
        return '+98'.substr($mobile, 1);
    }

}
