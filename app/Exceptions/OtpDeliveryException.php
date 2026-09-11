<?php

namespace App\Exceptions;

use RuntimeException;

class OtpDeliveryException extends RuntimeException
{
    public function __construct(
        string $message = 'OTP gagal dikirim.',
        ?\Throwable $previous = null,
        private readonly bool $outcomeUnknown = false,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function unknown(string $message, ?\Throwable $previous = null): self
    {
        return new self($message, $previous, true);
    }

    public function isOutcomeUnknown(): bool
    {
        return $this->outcomeUnknown;
    }
}
