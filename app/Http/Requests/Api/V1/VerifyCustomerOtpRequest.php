<?php

namespace App\Http\Requests\Api\V1;

use App\Support\PhoneNumber;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use InvalidArgumentException;

class VerifyCustomerOtpRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'phone' => [
                'bail',
                'required',
                'string',
                'max:20',
                function (string $attribute, mixed $value, Closure $fail): void {
                    try {
                        PhoneNumber::normalize((string) $value);
                    } catch (InvalidArgumentException) {
                        $fail('Format nomor WhatsApp tidak valid.');
                    }
                },
            ],
            'challenge_id' => [
                'required',
                'integer',
                'min:1',
            ],
            'otp' => [
                'required',
                'string',
                'digits:6',
            ],
            'device_name' => [
                'required',
                'string',
                'max:100',
            ],
        ];
    }
}
