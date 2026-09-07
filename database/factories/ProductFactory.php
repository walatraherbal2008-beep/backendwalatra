<?php

namespace Database\Factories;

use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Product> */
class ProductFactory extends Factory
{
    protected $model = Product::class;

    public function definition(): array
    {
        $name = 'Produk Herbal '.fake()->unique()->word();

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.fake()->unique()->numerify('###'),
            'product_code' => 'PROD-'.fake()->unique()->numerify('#####'),
            'bpom_number' => 'TR'.fake()->unique()->numerify('###########'),
            'status' => 'active',
        ];
    }
}
