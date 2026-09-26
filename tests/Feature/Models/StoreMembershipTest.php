<?php

namespace Tests\Feature\Models;

use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class StoreMembershipTest extends TestCase
{
    use RefreshDatabase;

    public function test_store_owner_must_be_an_active_verified_walatra_member_seller(): void
    {
        Role::findOrCreate('store_admin', 'web');
        $admin = User::factory()->create([
            'user_type' => 'admin',
            'status' => 'active',
        ]);
        $admin->assignRole('store_admin');

        $this->expectException(ValidationException::class);

        Store::factory()->create(['owner_user_id' => $admin->id]);
    }

    public function test_active_verified_member_seller_can_own_a_store(): void
    {
        Role::findOrCreate('store_admin', 'web');
        $member = User::factory()->create([
            'user_type' => 'customer',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
        $member->assignRole('store_admin');

        $store = Store::factory()->create(['owner_user_id' => $member->id]);

        $this->assertSame($member->id, $store->owner_user_id);
    }
}
