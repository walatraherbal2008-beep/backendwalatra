<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class CartSelectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'cart_item_ids' => ['sometimes', 'array', 'list'],
            'cart_item_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
        ];
    }
}
