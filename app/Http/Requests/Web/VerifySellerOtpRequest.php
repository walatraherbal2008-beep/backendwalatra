<?php

namespace App\Http\Requests\Web;

use Illuminate\Foundation\Http\FormRequest;

class VerifySellerOtpRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'otp' => ['required', 'string', 'digits:6'],
        ];
    }
}
