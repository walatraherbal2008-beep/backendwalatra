<?php

namespace Database\Factories;

use App\Models\ProductBundle;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<ProductBundle> */
class ProductBundleFactory extends Factory
{
    protected $model = ProductBundle::class;

    public function definition(): array
    {
        $name = 'Paket Herbal '.fake()->unique()->word();

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.fake()->unique()->numerify('###'),
            'code' => 'BND-'.fake()->unique()->numerify('########'),
            'description' => fake()->sentence(),
            'price' => fake()->randomFloat(2, 50000, 500000),
            'status' => 'active',
        ];
    }
}
