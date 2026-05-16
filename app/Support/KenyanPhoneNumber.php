<?php

namespace App\Support;

final class KenyanPhoneNumber
{
    /**
     * Normalize a Kenyan mobile number to local 07XXXXXXXX format when possible.
     */
    public static function normalizeToLocal(string $phone): string
    {
        $phone = preg_replace('/[\s\-\(\)]/', '', trim($phone)) ?? '';
        $phone = ltrim($phone, '+');

        if (preg_match('/^254\d{9}$/', $phone) === 1) {
            return '0'.substr($phone, 3);
        }

        if (preg_match('/^7\d{8}$/', $phone) === 1) {
            return '0'.$phone;
        }

        return $phone;
    }

    /**
     * Determine whether the given number is a local Kenyan mobile number.
     */
    public static function isLocalKenyanMobile(string $phone): bool
    {
        return preg_match('/^07\d{8}$/', self::normalizeToLocal($phone)) === 1;
    }
}
