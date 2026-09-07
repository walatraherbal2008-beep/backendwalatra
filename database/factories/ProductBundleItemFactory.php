<?php

namespace Database\Factories;

use App\Models\ProductBundle;
use App\Models\ProductBundleItem;
use App\Models\ProductVariant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ProductBundleItem> */
class ProductBundleItemFactory extends Factory
{
    protected $model = ProductBundleItem::class;

    public function definition(): array
    {
        return [
            'product_bundle_id' => ProductBundle::factory(),
            'product_variant_id' => ProductVariant::factory(),
            'quantity' => fake()->numberBetween(1, 3),
        ];
    }
}
