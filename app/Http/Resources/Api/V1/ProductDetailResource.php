<?php

namespace App\Http\Resources\Api\V1;

use App\Support\ListingImageConversions;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductDetailResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'product_code' => $this->product_code,
            'bpom_number' => $this->bpom_number,
            'categories' => $this->categories->map(fn ($category): array => [
                'id' => $category->id,
                'name' => $category->name,
                'slug' => $category->slug,
            ])->values(),
            'images' => $this->getMedia('images')->map(fn ($media): array => array_merge(
                ['id' => $media->id, 'name' => $media->name, 'order' => $media->order_column],
                ListingImageConversions::urls($media),
            ))->values(),
            'variants' => $this->variants->map(fn ($variant): array => [
                'id' => $variant->id,
                'name' => $variant->name,
                'sku' => $variant->sku,
                'image_url' => $variant->getFirstMediaUrl('image') ?: null,
                'price' => $variant->price,
                'weight_grams' => $variant->weight_grams,
                'attributes' => $variant->attributes,
            ])->values(),
            'stores' => ProductStoreOfferResource::collection(
                $this->listings->each(
                    // Pakai instance produk yang sama (variants/categories sudah
                    // eager) agar fallback gambar master di tiap offer tidak N+1.
                    fn ($listing) => $listing->setRelation('product', $this->resource),
                ),
            ),
        ];
    }
}
