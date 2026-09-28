<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class AddCartItemRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            'type' => $this->input(
                'type',
                $this->filled('bundle_listing_id') ? 'bundle' : 'product',
            ),
        ]);
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'type' => ['nullable', 'in:product,bundle'],
            'listing_id' => ['required_if:type,product', 'nullable', 'integer', 'min:1'],
            'variant_id' => ['required_if:type,product', 'nullable', 'integer', 'min:1'],
            'bundle_listing_id' => ['required_if:type,bundle', 'nullable', 'integer', 'min:1'],
            'quantity' => ['required', 'integer', 'min:1', 'max:99'],
        ];
    }
}
