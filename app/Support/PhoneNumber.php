<?php

namespace App\Support;

use InvalidArgumentException;

class PhoneNumber
{
    public static function normalize(string $phone): string
    {
        $phone = preg_replace('/[^0-9+]/', '', trim($phone));

        if ($phone === null || $phone === '') {
            throw new InvalidArgumentException('Nomor Whatsapp tidak valid');
        }
        if (str_starts_with($phone, '+62')) {
            $phone = '62'.substr($phone, 3);
        } elseif (str_starts_with($phone, '08')) {
            $phone = '62'.substr($phone, 1);
        } elseif (str_starts_with($phone, '8')) {
            $phone = '62'.$phone;
        }

        if (! preg_match('/^628[0-9]{8,13}$/', $phone)) {
            throw new InvalidArgumentException('Format nomor WhatsApp tidak valid.');
        }

        return $phone;
    }
}
