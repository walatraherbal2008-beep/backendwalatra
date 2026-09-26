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
            'city' => $this->city,
            'district' => $this->district,
            'postal_code' => $this->postal_code,
            'address' => $this->address,
            'is_default' => $this->is_default,
        ];
    }
}
