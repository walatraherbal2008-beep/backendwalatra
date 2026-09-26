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
}
