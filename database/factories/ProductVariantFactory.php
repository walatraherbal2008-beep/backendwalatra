<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ProductVariant> */
class ProductVariantFactory extends Factory
{
    protected $model = ProductVariant::class;

    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'name' => fake()->randomElement(['50 Kapsul', '100 Kapsul', '250 ml', '500 ml']),
            'sku' => 'SKU-'.fake()->unique()->numerify('########'),
            'price' => fake()->randomFloat(2, 25000, 250000),
            'weight_grams' => fake()->numberBetween(100, 1500),
            'attributes' => null,
            'status' => 'active',
        ];
    }
}
