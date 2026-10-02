<?php

namespace App\Http\Requests\Api\V1;

use App\Support\PhoneNumber;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use InvalidArgumentException;

class StoreAddressRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'label' => ['required', 'string', 'max:50'],
            'name' => ['required', 'string', 'min:2', 'max:255'],
            'phone' => [
                'required',
                'string',
                'max:20',
                function (string $attribute, mixed $value, Closure $fail): void {
                    try {
                        PhoneNumber::normalize((string) $value);
                    } catch (InvalidArgumentException $exception) {
                        $fail($exception->getMessage());
                    }
                },
            ],
            'province' => ['required', 'string', 'max:100'],
            'province_id' => ['nullable', 'string', 'max:100', 'required_with:city_id,district_id,sub_district_id'],
            'province_name' => ['nullable', 'string', 'max:100'],
            'city' => ['required', 'string', 'max:100'],
            'city_id' => ['nullable', 'string', 'max:100', 'required_with:province_id,district_id,sub_district_id'],
            'city_name' => ['nullable', 'string', 'max:100'],
            'district' => ['nullable', 'string', 'max:100'],
            'district_id' => ['nullable', 'string', 'max:100', 'required_with:province_id,city_id,sub_district_id'],
            'district_name' => ['nullable', 'string', 'max:100'],
            'sub_district_id' => ['nullable', 'string', 'max:100', 'required_with:province_id,city_id,district_id'],
            'sub_district_name' => ['nullable', 'string', 'max:100'],
            'postal_code' => ['required', 'string', 'max:10'],
            'address' => ['required', 'string', 'max:1000'],
            'address_note' => ['nullable', 'string', 'max:500'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'is_default' => ['sometimes', 'boolean'],
        ];
    }
}
