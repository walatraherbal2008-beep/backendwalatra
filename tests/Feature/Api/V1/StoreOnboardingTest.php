<?php

namespace Tests\Feature\Api\V1;

use App\Models\MarketplaceSetting;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class StoreOnboardingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('customer', 'web');
        Role::findOrCreate('store_admin', 'web');
        Notification::fake();
    }

    public function test_member_can_submit_self_service_store_registration(): void
    {
        $member = User::factory()->create([
            'email' => null,
            'password' => null,
            'user_type' => 'customer',
            'status' => 'active',
        ]);
        Sanctum::actingAs($member, ['customer-api']);

        $response = $this->postJson('/api/v1/store-onboarding', [
            'name' => 'Toko Mandiri Walatra',
            'store_name' => 'Herbal Sehat Mandiri',
            'email' => 'mandiri@example.com',
            'password' => 'PasswordMandiri123!',
            'city' => 'Bandung',
        ])->assertCreated()
            ->assertJsonPath('code', 'STORE_REGISTRATION_SUBMITTED')
            ->assertJsonPath('data.store.status', 'pending_approval');

        $storeId = $response->json('data.store.id');

        $this->assertDatabaseHas('stores', [
            'id' => $storeId,
            'owner_user_id' => $member->id,
            'name' => 'Herbal Sehat Mandiri',
            'status' => 'pending_approval',
        ]);
        $this->assertDatabaseHas('store_memberships', [
            'store_id' => $storeId,
            'user_id' => $member->id,
            'role' => 'owner',
            'status' => 'active',
        ]);
        $this->assertTrue($member->fresh()->hasRole('store_admin'));
        Notification::assertSentOnDemand(\App\Notifications\VerifyStoreAdminEmailNotification::class);
    }

    public function test_superadmin_can_close_self_service_store_registration(): void
    {
        MarketplaceSetting::query()
            ->where('key', 'self_service_store_registration_enabled')
            ->update(['enabled' => false]);
        $member = User::factory()->create(['email' => null, 'password' => null]);
        Sanctum::actingAs($member, ['customer-api']);

        $this->postJson('/api/v1/store-onboarding', [
            'name' => 'Toko Ditutup',
            'email' => 'ditutup@example.com',
            'password' => 'PasswordDitutup123!',
        ])->assertForbidden()
            ->assertJsonPath('code', 'SELF_SERVICE_STORE_REGISTRATION_DISABLED');
    }

    public function test_verified_email_link_unlocks_store_admin_access_but_not_store_yet(): void
    {
        $member = User::factory()->create([
            'email' => null,
            'password' => null,
            'email_verified_at' => null,
        ]);
        Sanctum::actingAs($member, ['customer-api']);
        $response = $this->postJson('/api/v1/store-onboarding', [
            'name' => 'Toko Verifikasi',
            'email' => 'verifikasi@example.com',
            'password' => 'PasswordVerifikasi123!',
        ])->assertCreated();

        $user = $member->fresh();
        $url = URL::temporarySignedRoute(
            'store-onboarding.verify-email',
            now()->addMinutes(5),
            ['user' => $user->id, 'hash' => sha1($user->email)],
        );

        $this->getJson($url)->assertOk()->assertJsonPath('code', 'EMAIL_VERIFIED');
        $this->assertNotNull($user->fresh()->email_verified_at);
        $this->assertSame('pending_approval', Store::findOrFail($response->json('data.store.id'))->status);
    }

    public function test_store_admin_can_invite_and_member_can_accept_once(): void
    {
        $owner = User::factory()->create(['email' => 'owner@example.com']);
        $owner->assignRole('store_admin');
        $store = Store::factory()->create(['owner_user_id' => $owner->id, 'status' => 'active']);
        $store->admins()->syncWithoutDetaching([$owner->id => ['role' => 'owner', 'status' => 'active']]);
        Sanctum::actingAs($owner, ['customer-api']);

        $created = $this->postJson('/api/v1/store/admin-invitations', [
            'email' => 'admin-kedua@example.com',
        ])->assertCreated()
            ->assertJsonPath('code', 'STORE_ADMIN_INVITATION_CREATED');

        $invitee = User::factory()->create([
            'email' => null,
            'password' => null,
        ]);
        Sanctum::actingAs($invitee, ['customer-api']);

        $this->postJson('/api/v1/store/admin-invitations/accept', [
            'token' => $created->json('data.token'),
            'email' => 'admin-kedua@example.com',
            'name' => 'Admin Kedua',
            'password' => 'PasswordAdminKedua123!',
        ])->assertOk()->assertJsonPath('code', 'STORE_ADMIN_INVITATION_ACCEPTED');

        $this->assertDatabaseHas('store_memberships', [
            'store_id' => $store->id,
            'user_id' => $invitee->id,
            'role' => 'admin',
            'status' => 'active',
        ]);
        $this->assertTrue($invitee->fresh()->hasRole('store_admin'));
    }
}
