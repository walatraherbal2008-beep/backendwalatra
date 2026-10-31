<?php

namespace Tests\Feature\Services;

use App\Models\User;
use App\Services\Marketplace\UserStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class UserStatusServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
    }

    public function test_suspending_customer_stores_reason_and_revokes_tokens(): void
    {
        $admin = User::factory()->create(['user_type' => 'admin']);
        $customer = User::factory()->create(['user_type' => 'customer', 'status' => 'active']);
        $customer->createToken('android', ['customer-api']);
        $customer->createToken('web', ['customer-api']);

        $updated = app(UserStatusService::class)->change(
            user: $customer,
            status: 'suspended',
            reason: 'Aktivitas tidak wajar',
            changedBy: $admin,
        );

        $this->assertSame('suspended', $updated->status);
        $this->assertSame('Aktivitas tidak wajar', $updated->status_reason);
        $this->assertSame($admin->id, $updated->status_changed_by);
        $this->assertNotNull($updated->status_changed_at);
        $this->assertDatabaseHas('user_status_histories', [
            'user_id' => $customer->id,
            'previous_status' => 'active',
            'new_status' => 'suspended',
            'reason' => 'Aktivitas tidak wajar',
            'changed_by' => $admin->id,
        ]);
        Notification::assertSentOnDemand(\App\Notifications\CustomerAccountStatusChanged::class);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_customer_can_be_activated_again_without_creating_a_token(): void
    {
        $admin = User::factory()->create(['user_type' => 'admin']);
        $customer = User::factory()->create(['user_type' => 'customer', 'status' => 'blocked']);

        app(UserStatusService::class)->change(
            user: $customer,
            status: 'active',
            reason: 'Pemeriksaan selesai',
            changedBy: $admin,
        );

        $this->assertDatabaseHas('users', [
            'id' => $customer->id,
            'status' => 'active',
            'status_reason' => 'Pemeriksaan selesai',
        ]);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_admin_cannot_change_own_status_or_non_customer_status(): void
    {
        $admin = User::factory()->create(['user_type' => 'admin']);

        $this->expectException(\Illuminate\Validation\ValidationException::class);

        app(UserStatusService::class)->change(
            user: $admin,
            status: 'blocked',
            reason: 'Tidak valid',
            changedBy: $admin,
        );
    }
}
