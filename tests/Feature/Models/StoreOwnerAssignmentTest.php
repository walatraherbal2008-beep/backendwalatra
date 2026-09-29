<?php

namespace Tests\Feature\Models;

use App\Models\Store;
use App\Models\StoreOwnerHistory;
use App\Models\User;
use App\Services\Marketplace\StoreOwnerAssignmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class StoreOwnerAssignmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_valid_member_can_be_assigned_and_history_is_recorded(): void
    {
        Role::findOrCreate('store_admin', 'web');
        $member = User::factory()->create([
            'user_type' => 'customer',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
        $member->assignRole('store_admin');
        $store = Store::factory()->create(['status' => 'pending_owner']);

        app(StoreOwnerAssignmentService::class)->assign($store, $member, reason: 'Migrasi owner toko');

        $this->assertDatabaseHas('stores', [
            'id' => $store->id,
            'owner_user_id' => $member->id,
            'status' => 'pending_approval',
        ]);
        $this->assertDatabaseHas('store_owner_histories', [
            'store_id' => $store->id,
            'new_owner_user_id' => $member->id,
            'reason' => 'Migrasi owner toko',
        ]);
    }

    public function test_admin_cannot_be_assigned_as_store_owner(): void
    {
        Role::findOrCreate('store_admin', 'web');
        $admin = User::factory()->create([
            'user_type' => 'admin',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
        $admin->assignRole('store_admin');
        $store = Store::factory()->create(['status' => 'pending_owner']);

        $this->expectException(ValidationException::class);

        app(StoreOwnerAssignmentService::class)->assign($store, $admin);
    }

    public function test_member_cannot_own_two_stores(): void
    {
        Role::findOrCreate('store_admin', 'web');
        $member = User::factory()->create([
            'user_type' => 'customer',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
        $member->assignRole('store_admin');
        Store::factory()->create(['owner_user_id' => $member->id]);
        $otherStore = Store::factory()->create(['status' => 'pending_owner']);

        $this->expectException(ValidationException::class);

        app(StoreOwnerAssignmentService::class)->assign($otherStore, $member);
    }
}
