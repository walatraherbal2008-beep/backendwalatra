<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class CreateOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
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
}
