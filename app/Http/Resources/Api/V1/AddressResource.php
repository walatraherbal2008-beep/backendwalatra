<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AddressResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'label' => $this->label,
            'name' => $this->name,
            'phone' => $this->phone,
            'province' => $this->province,
            'province_id' => $this->province_id,
            'province_name' => $this->province_name,
            'city' => $this->city,
            'city_id' => $this->city_id,
            'city_name' => $this->city_name,
            'district' => $this->district,
            'district_id' => $this->district_id,
            'district_name' => $this->district_name,
            'sub_district_id' => $this->sub_district_id,
            'sub_district_name' => $this->sub_district_name,
            'postal_code' => $this->postal_code,
            'address' => $this->address,
            'address_note' => $this->address_note,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'is_default' => $this->is_default,
        ];
    }
}
