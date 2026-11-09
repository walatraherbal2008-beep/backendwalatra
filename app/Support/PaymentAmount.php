<?php

namespace App\Support;

use Illuminate\Validation\ValidationException;

final class PaymentAmount
{
    public static function rupiah(string|int $value): int
    {
        $value = (string) $value;
        if (! preg_match('/^([0-9]{1,13})(?:\.0{1,2})?$/D', $value, $matches)) {
            throw ValidationException::withMessages(['amount' => 'Nominal pembayaran harus berupa rupiah bulat tanpa pembulatan.']);
        }

        return (int) $matches[1];
    }
}
