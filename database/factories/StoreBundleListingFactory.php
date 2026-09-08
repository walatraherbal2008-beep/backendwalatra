<?php

namespace Database\Factories;

use App\Models\ProductBundle;
use App\Models\Store;
use App\Models\StoreBundleListing;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<StoreBundleListing> */
class StoreBundleListingFactory extends Factory
{
    protected $model = StoreBundleListing::class;

    public function definition(): array
    {
        return [
            'store_id' => Store::factory(),
            'product_bundle_id' => ProductBundle::factory(),
            'description' => fake()->sentence(),
            'status' => 'active',
            'sort_order' => 0,
            'published_at' => now(),
        ];
    }
}
