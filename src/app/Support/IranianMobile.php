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

    /** Fixed, immutable expression shared by production lookup and its index. */
    public static function postgresStorageExpression(): string
    {
        // E-string escapes keep every Unicode whitespace code point reviewable.
        // Constants must stay literal so PostgreSQL generic plans match the index.
        return <<<'SQL'
            regexp_replace(translate(mobile, '۰۱۲۳۴۵۶۷۸۹٠١٢٣٤٥٦٧٨٩', '01234567890123456789'), E'[\\s()\\-\u0085\u00A0\u1680\u2000\u2001\u2002\u2003\u2004\u2005\u2006\u2007\u2008\u2009\u200A\u2028\u2029\u202F\u205F\u3000]+', '', 'g')
            SQL;
    }

}
