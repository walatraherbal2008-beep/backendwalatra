<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\ProductBundle;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ProductBundle> */
class ProductBundleFactory extends Factory
{
    protected $model = ProductBundle::class;

    public function definition(): array
    {
        return [
            'product_id' => Product::factory()->bundle(),
            'status' => 'active',
        ];
    }
}
