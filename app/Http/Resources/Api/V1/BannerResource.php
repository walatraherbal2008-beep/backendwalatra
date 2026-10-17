<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BannerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $media = $this->getFirstMedia('image');

        return [
            'id' => $this->id,
            'name' => $this->name,
            'image_url' => $media?->getUrl(),
            'image_preview_url' => $media?->hasGeneratedConversion('preview')
                ? $media->getUrl('preview')
                : null,
            'cta_url' => $this->cta_url,
            'starts_at' => $this->starts_at?->toIso8601String(),
            'ends_at' => $this->ends_at?->toIso8601String(),
            'sort_order' => $this->sort_order,
        ];
    }
}
