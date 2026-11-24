<?php

namespace Tests\Feature\Web;

use App\Models\MarketplaceSetting;
use App\Models\OtpChallenge;
use App\Models\Store;
use App\Models\User;
use App\Notifications\VerifyStoreAdminEmailNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
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
        Notification::assertSentOnDemand(VerifyStoreAdminEmailNotification::class);
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

    public function test_registration_saves_everpro_location_ids_and_labels(): void
    {
        $member = User::factory()->create(['email' => null]);
        $location = [
            'province_id' => 'province==', 'province' => 'Jawa Barat',
            'city_id' => 'city=', 'city' => 'Bandung Barat',
            'district_id' => 'district=', 'district' => 'Batujajar',
            'sub_district_id' => 'sub-district=', 'sub_district_name' => 'Bojonghaleuang',
            'postal_code' => '40561', 'address' => 'Jalan Toko Nomor 10',
        ];

        $this->actingAs($member)->post('/seller/register', [
            'name' => 'Seller Alamat', 'email' => 'alamat@example.com',
            'password' => 'rahasia6', 'store_name' => 'Toko Alamat', ...$location,
        ])->assertRedirect(route('seller.register.status'));

        $this->assertDatabaseHas('stores', ['owner_user_id' => $member->id, ...$location]);
    }

    public function test_partial_everpro_hierarchy_is_rejected_without_creating_a_store(): void
    {
        $member = User::factory()->create(['email' => null]);

        $this->actingAs($member)->post('/seller/register', [
            'name' => 'Seller Alamat', 'email' => 'alamat@example.com',
            'password' => 'rahasia6', 'province_id' => 'province==', 'province' => 'Jawa Barat',
        ])->assertSessionHasErrors(['city_id', 'district_id', 'sub_district_id']);

        $this->assertDatabaseCount('stores', 0);
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

    #[DataProvider('storeStatusCases')]
    public function test_status_page_reflects_the_current_store_status(string $status, string $label, string $heading): void
    {
        $member = User::factory()->create(['email_verified_at' => now()]);
        $member->assignRole('store_admin');
        Store::factory()->create(['owner_user_id' => $member->id, 'status' => $status]);

        $response = $this->actingAs($member)->get('/seller/register/status')
            ->assertOk()
            ->assertSee($label)
            ->assertSee($heading);

        if ($status !== 'pending_approval') {
            $response->assertDontSee('Menunggu persetujuan')->assertDontSee('Toko sedang diperiksa.');
        }

        if ($status === 'active') {
            $response->assertSee('Buka panel toko');
        } else {
            $response->assertDontSee('Buka panel toko');
        }
    }

    public static function storeStatusCases(): array
    {
        return [
            'pending' => ['pending_approval', 'Menunggu persetujuan', 'Toko sedang diperiksa.'],
            'approved' => ['active', 'Aktif', 'Toko Anda sudah disetujui.'],
            'rejected' => ['rejected', 'Ditolak', 'Pengajuan toko ditolak.'],
            'suspended' => ['suspended', 'Ditangguhkan', 'Toko Anda ditangguhkan.'],
            'inactive' => ['inactive', 'Tidak aktif', 'Toko Anda tidak aktif.'],
            'pending owner' => ['pending_owner', 'Menunggu pemilik member', 'Toko menunggu penetapan pemilik.'],
        ];
    }
}
