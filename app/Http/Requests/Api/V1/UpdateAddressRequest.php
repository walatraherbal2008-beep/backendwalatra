<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class UpdateAddressRequest extends StoreAddressRequest
{
    public function rules(): array
    {
        return collect(parent::rules())
            ->map(fn (array $rules): array => array_merge(['sometimes'], $rules))
            ->all();
    }
}
