<?php

namespace Tests\Feature\Filament;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CustomerManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_superadmin_can_export_customer_csv(): void
    {
        Role::findOrCreate('super_admin', 'web');
        $admin = User::factory()->create(['user_type' => 'admin']);
        $admin->assignRole('super_admin');
        User::factory()->create(['name' => 'Customer Export', 'user_type' => 'customer']);

        $this->actingAs($admin)
            ->get(route('superadmin.customers.export'))
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');
    }

    public function test_non_superadmin_cannot_export_customer_csv(): void
    {
        $customer = User::factory()->create(['user_type' => 'customer']);

        $this->actingAs($customer)
            ->get(route('superadmin.customers.export'))
            ->assertForbidden();
    }
}
