<?php

namespace Tests\Feature\Policies;

use App\Models\Store;
use App\Models\StoreBundleListing;
use App\Models\StoreProductListing;
use App\Models\User;
use App\Policies\StoreBundleListingPolicy;
use App\Policies\StoreProductListingPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class StoreListingPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_store_admin_can_manage_only_its_product_listing(): void
    {
        $admin = $this->storeAdminWithStore();
        $otherStore = Store::factory()->create();
        $ownListing = StoreProductListing::factory()->create(['store_id' => $admin->store->id, 'status' => 'draft']);
        $otherListing = StoreProductListing::factory()->create(['store_id' => $otherStore->id, 'status' => 'draft']);
        $policy = new StoreProductListingPolicy;

        $this->assertTrue($policy->view($admin, $ownListing));
        $this->assertTrue($policy->update($admin, $ownListing));
        $this->assertTrue($policy->delete($admin, $ownListing));
        $this->assertFalse($policy->view($admin, $otherListing));
        $this->assertFalse($policy->update($admin, $otherListing));
        $this->assertFalse($policy->delete($admin, $otherListing));
    }

    public function test_store_admin_can_manage_only_its_bundle_listing(): void
    {
        $admin = $this->storeAdminWithStore();
        $otherStore = Store::factory()->create();
        $ownListing = StoreBundleListing::factory()->create(['store_id' => $admin->store->id, 'status' => 'draft']);
        $otherListing = StoreBundleListing::factory()->create(['store_id' => $otherStore->id, 'status' => 'draft']);
        $policy = new StoreBundleListingPolicy;

        $this->assertTrue($policy->view($admin, $ownListing));
        $this->assertTrue($policy->update($admin, $ownListing));
        $this->assertTrue($policy->delete($admin, $ownListing));
        $this->assertFalse($policy->view($admin, $otherListing));
        $this->assertFalse($policy->update($admin, $otherListing));
        $this->assertFalse($policy->delete($admin, $otherListing));
    }

    public function test_inactive_store_cannot_manage_existing_listing(): void
    {
        $admin = $this->storeAdminWithStore(['status' => 'inactive']);
        $listing = StoreProductListing::factory()->create(['store_id' => $admin->store->id, 'status' => 'draft']);

        $this->assertFalse((new StoreProductListingPolicy)->view($admin, $listing));
        $this->assertFalse((new StoreProductListingPolicy)->create($admin));
    }

    private function storeAdminWithStore(array $storeAttributes = []): User
    {
        Role::findOrCreate('store_admin', 'web');
        $admin = User::factory()->create([
            'user_type' => 'customer',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
        $admin->assignRole('store_admin');
        Store::factory()->create(['owner_user_id' => $admin->id, ...$storeAttributes]);

        return $admin->load('store');
    }
}
