<?php

namespace Tests\Feature\Web;

use App\Models\MarketplaceSetting;
use App\Models\OtpChallenge;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SellerRegistrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('customer', 'web');
        Role::findOrCreate('store_admin', 'web');
        Notification::fake();
        Queue::fake();
    }

    public function test_guest_is_redirected_to_seller_login(): void
    {
        $this->get('/seller/register')
            ->assertRedirect(route('seller.login'));
    }

    public function test_otp_verification_creates_web_session(): void
    {
        $challenge = OtpChallenge::query()->create([
            'phone' => '628123456789',
            'purpose' => 'customer_login',
            'code_hash' => Hash::make('123456'),
            'expires_at' => now()->addMinutes(5),
            'attempts' => 0,
            'max_attempts' => 5,
        ]);

        $this->withSession([
            'seller_otp.phone' => '628123456789',
            'seller_otp.challenge_id' => $challenge->id,
        ])->post('/seller/login/verify-otp', [
            'otp' => '123456',
        ])->assertRedirect(route('seller.register'));

        $this->assertAuthenticated();
        $this->assertDatabaseHas('users', [
            'phone' => '628123456789',
            'user_type' => 'customer',
            'status' => 'active',
        ]);
    }

    public function test_authenticated_member_can_register_seller_and_store(): void
    {
        $member = User::factory()->create([
            'email' => null,
            'password' => null,
            'user_type' => 'customer',
            'status' => 'active',
        ]);

        $this->actingAs($member)->get('/seller/register')->assertOk();

        $this->actingAs($member)->post('/seller/register', [
            'name' => 'Budi Walatra',
            'email' => 'budi@example.com',
            'password' => 'rahasia6',
            'store_name' => 'Toko Herbal Budi',
            'description' => 'Toko herbal pilihan.',
            'contact_phone' => '628123456789',
            'city' => 'Bandung',
        ])->assertRedirect(route('seller.register.status'));

        $this->assertDatabaseHas('stores', [
            'name' => 'Toko Herbal Budi',
            'owner_user_id' => $member->id,
            'status' => 'pending_approval',
        ]);
        $this->assertTrue($member->fresh()->hasRole('store_admin'));
        Notification::assertSentOnDemand(\App\Notifications\VerifyStoreAdminEmailNotification::class);
    }

    public function test_registration_requires_six_character_password(): void
    {
        $member = User::factory()->create([
            'email' => null,
            'password' => null,
            'user_type' => 'customer',
            'status' => 'active',
        ]);

        $this->actingAs($member)->post('/seller/register', [
            'name' => 'Seller Pendek',
            'email' => 'pendek@example.com',
            'password' => '12345',
            'store_name' => 'Toko Pendek',
        ])->assertSessionHasErrors('password');

        $this->assertDatabaseMissing('stores', ['name' => 'Toko Pendek']);
    }

    public function test_registration_can_be_disabled_by_superadmin_setting(): void
    {
        MarketplaceSetting::query()
            ->where('key', 'self_service_store_registration_enabled')
            ->update(['enabled' => false]);

        $this->get('/seller/register')
            ->assertOk()
            ->assertSee('Pendaftaran seller sedang ditutup');
    }

    public function test_status_page_shows_pending_store(): void
    {
        $member = User::factory()->create(['email' => 'seller@example.com']);
        $member->assignRole('store_admin');
        $store = Store::factory()->create([
            'owner_user_id' => $member->id,
            'status' => 'pending_approval',
        ]);

        $this->actingAs($member)->get('/seller/register/status')
            ->assertOk()
            ->assertSee($store->name)
            ->assertSee('Menunggu persetujuan');
    }
}
