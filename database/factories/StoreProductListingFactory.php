<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\Store;
use App\Models\StoreProductListing;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<StoreProductListing> */
class StoreProductListingFactory extends Factory
{
    protected $model = StoreProductListing::class;

    public function definition(): array
    {
        return [
            'store_id' => Store::factory(),
            'product_id' => Product::factory(),
            'status' => 'active',
            'sort_order' => 0,
            'published_at' => now(),
        ];
    }
}
