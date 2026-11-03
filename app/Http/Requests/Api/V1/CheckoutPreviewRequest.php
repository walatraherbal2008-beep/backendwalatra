<?php

namespace App\Http\Requests\Api\V1;

class CheckoutPreviewRequest extends CreateOrderRequest
{
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'address_id' => ['nullable', 'integer', 'min:1'],
            'shipping_address' => ['sometimes', 'required', 'array', 'min:1'],
        ];
    }
}
