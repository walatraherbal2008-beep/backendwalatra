<?php

namespace Database\Factories;

use App\Models\Store;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Store> */
class StoreFactory extends Factory
{
    protected $model = Store::class;

    public function definition(): array
    {
        $name = fake()->unique()->company().' Herbal';

        return [
            'name' => $name,
            'slug' => Str::slug($name),
            'code' => 'STORE-'.fake()->unique()->numerify('#####'),
            'description' => fake()->optional()->sentence(),
            'contact_phone' => '628'.fake()->numerify('##########'),
            'contact_email' => fake()->unique()->safeEmail(),
            'province' => 'Jawa Barat',
            'city' => 'Bandung',
            'district' => 'Coblong',
            'postal_code' => '40132',
            'address' => fake()->address(),
            'latitude' => -6.914744,
            'longitude' => 107.609810,
            'status' => 'active',
            'email_verified_at' => now(),
            'approved_at' => now(),
            'opened_at' => now(),
        ];
    }

    public function pendingApproval(): static
    {
        return $this->state(fn (): array => [
            'status' => 'pending_approval',
            'email_verified_at' => null,
            'approved_at' => null,
        ]);
    }
}
