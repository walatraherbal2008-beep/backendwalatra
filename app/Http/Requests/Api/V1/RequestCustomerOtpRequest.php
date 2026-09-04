<?php

namespace App\Http\Requests\Api\V1;

use App\Support\PhoneNumber;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use InvalidArgumentException;

class RequestCustomerOtpRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'phone' => [
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
        ];
    }
}
