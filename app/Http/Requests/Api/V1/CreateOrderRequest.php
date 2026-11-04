<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class CreateOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'checkout_token' => ['sometimes', 'required', 'string', 'max:4096'],
            'cart_item_ids' => ['sometimes', 'required', 'array', 'list', 'min:1'],
            'cart_item_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
            'address_id' => ['nullable', 'integer', 'min:1', 'required_without:shipping_address'],
            'shipping_address' => ['nullable', 'array', 'required_without:address_id'],
            'shipping_address.name' => ['required_with:shipping_address', 'string', 'min:2', 'max:255'],
            'shipping_address.phone' => ['required_with:shipping_address', 'string', 'max:20'],
            'shipping_address.address' => ['required_with:shipping_address', 'string', 'max:1000'],
            'shipping_address.address_note' => ['nullable', 'string', 'max:500'],
            'shipping_address.city' => ['required_with:shipping_address', 'string', 'max:100'],
            'shipping_address.city_id' => ['nullable', 'string', 'max:100', 'required_with:shipping_address.province_id,shipping_address.district_id,shipping_address.sub_district_id'],
            'shipping_address.city_name' => ['nullable', 'string', 'max:100'],
            'shipping_address.district' => ['nullable', 'string', 'max:100'],
            'shipping_address.district_id' => ['nullable', 'string', 'max:100', 'required_with:shipping_address.province_id,shipping_address.city_id,shipping_address.sub_district_id'],
            'shipping_address.district_name' => ['nullable', 'string', 'max:100'],
            'shipping_address.province' => ['required_with:shipping_address', 'string', 'max:100'],
            'shipping_address.province_id' => ['nullable', 'string', 'max:100', 'required_with:shipping_address.city_id,shipping_address.district_id,shipping_address.sub_district_id'],
            'shipping_address.province_name' => ['nullable', 'string', 'max:100'],
            'shipping_address.postal_code' => ['required_with:shipping_address', 'string', 'max:10'],
            'shipping_address.sub_district_id' => ['nullable', 'string', 'max:100', 'required_with:shipping_address.province_id,shipping_address.city_id,shipping_address.district_id'],
            'shipping_address.sub_district_name' => ['nullable', 'string', 'max:100'],
            'shipping_address.latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'shipping_address.longitude' => ['nullable', 'numeric', 'between:-180,180'],
        ];
    }

    public function selectedCartItems(Collection $items): Collection
    {
        $ids = $this->validated('cart_item_ids');

        if ($ids === null) {
            return $items;
        }

        $selected = $items->whereIn('id', $ids)->values();

        if ($selected->count() !== count($ids)) {
            throw ValidationException::withMessages([
                'cart_item_ids' => 'Sebagian item yang dipilih tidak tersedia di cart Anda. Silakan muat ulang cart.',
            ]);
        }

        return $selected;
    }
}
