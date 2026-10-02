<?php

namespace Tests\Feature\Api\V1;

use App\Models\Address;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AddressTest extends TestCase
{
    use RefreshDatabase;

    public function test_member_can_manage_only_own_addresses(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        Sanctum::actingAs($user, ['customer-api']);

        $created = $this->postJson('/api/v1/addresses', [
            'label' => 'Rumah',
            'name' => 'Member Walatra',
            'phone' => '628123456789',
            'province' => 'Jawa Barat',
            'city' => 'Bandung',
            'postal_code' => '40123',
            'address' => 'Jl. Herbal No. 1',
            'is_default' => true,
        ])->assertCreated()
            ->assertJsonPath('data.is_default', true);

        $addressId = $created->json('data.id');
        $otherAddress = Address::query()->create([
            'user_id' => $otherUser->id,
            'label' => 'Kantor',
            'name' => 'Other',
            'phone' => '628123456780',
            'province' => 'Jawa Barat',
            'city' => 'Bandung',
            'postal_code' => '40124',
            'address' => 'Jl. Lain No. 2',
        ]);

        $this->getJson('/api/v1/addresses')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->patchJson('/api/v1/addresses/'.$otherAddress->id, ['label' => 'Tidak boleh'])
            ->assertNotFound();
    }

    public function test_member_can_save_everpro_location_snapshot(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['customer-api']);

        $response = $this->postJson('/api/v1/addresses', [
            'label' => 'Rumah',
            'name' => 'Member Walatra',
            'phone' => '081234567890',
            'province' => 'Jawa Barat',
            'province_id' => '32',
            'province_name' => 'Jawa Barat',
            'city' => 'Bandung',
            'city_id' => '3273',
            'city_name' => 'Kota Bandung',
            'district' => 'Coblong',
            'district_id' => '327301',
            'district_name' => 'Coblong',
            'sub_district_id' => '3273011001',
            'sub_district_name' => 'Dago',
            'postal_code' => '40135',
            'address' => 'Jl. Herbal No. 1',
            'address_note' => 'Pagar hitam',
            'latitude' => -6.885,
            'longitude' => 107.613,
        ])->assertCreated()
            ->assertJsonPath('data.phone', '6281234567890')
            ->assertJsonPath('data.sub_district_id', '3273011001')
            ->assertJsonPath('data.sub_district_name', 'Dago');

        $this->assertDatabaseHas('addresses', [
            'id' => $response->json('data.id'),
            'phone' => '6281234567890',
            'sub_district_id' => '3273011001',
            'postal_code' => '40135',
        ]);
    }

    public function test_invalid_shipping_phone_is_rejected(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['customer-api']);

        $this->postJson('/api/v1/addresses', [
            'label' => 'Rumah',
            'name' => 'Member Walatra',
            'phone' => '021123456',
            'province' => 'Jawa Barat',
            'city' => 'Bandung',
            'postal_code' => '40123',
            'address' => 'Jl. Herbal No. 1',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('phone');
    }

    public function test_everpro_location_ids_must_be_complete_when_used(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['customer-api']);

        $this->postJson('/api/v1/addresses', [
            'label' => 'Rumah',
            'name' => 'Member Walatra',
            'phone' => '628123456789',
            'province' => 'Jawa Barat',
            'province_id' => '32',
            'city' => 'Bandung',
            'postal_code' => '40123',
            'address' => 'Jl. Herbal No. 1',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['city_id', 'district_id', 'sub_district_id']);
    }
}
