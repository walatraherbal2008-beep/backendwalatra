<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateSelfServiceStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'store_name' => ['nullable', 'string', 'max:255'],
            'email' => [
                'required', 'email', 'max:255',
                Rule::unique('users', 'email')->ignore($this->user()?->getKey()),
            ],
            'password' => ['required', 'string', 'min:6'],
            'description' => ['nullable', 'string', 'max:5000'],
            'contact_phone' => ['nullable', 'string', 'max:20'],
            'contact_email' => ['nullable', 'email', 'max:255'],
            'province' => ['nullable', 'string', 'max:100', 'required_with:province_id'],
            'province_id' => ['nullable', 'string', 'max:100', 'required_with:city_id,district_id,sub_district_id'],
            'city' => ['nullable', 'string', 'max:100', 'required_with:city_id'],
            'city_id' => ['nullable', 'string', 'max:100', 'required_with:province_id,district_id,sub_district_id'],
            'district' => ['nullable', 'string', 'max:100', 'required_with:district_id'],
            'district_id' => ['nullable', 'string', 'max:100', 'required_with:province_id,city_id,sub_district_id'],
            'sub_district_id' => ['nullable', 'string', 'max:100', 'required_with:province_id,city_id,district_id'],
            'sub_district_name' => ['nullable', 'string', 'max:100', 'required_with:sub_district_id'],
            'postal_code' => ['nullable', 'string', 'max:10'],
            'address' => ['nullable', 'string', 'max:5000'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
        ];
    }
}
