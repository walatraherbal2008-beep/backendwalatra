<?php

namespace Tests\Feature\Api\V1;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_login_and_receive_a_token(): void
    {
        $user = User::factory()->create([
            'email' => 'customer@example.com',
            'password' => 'password',
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password',
            'device_name' => 'test-device',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.email', $user->email)
            ->assertJsonPath('data.token_type', 'Bearer')
            ->assertJsonStructure(['data' => ['token']]);

        $this->assertDatabaseHas('personal_access_tokens', [
            'tokenable_id' => $user->id,
            'name' => 'test-device',
        ]);
    }

    public function test_invalid_credentials_are_rejected(): void
    {
        User::factory()->create(['email' => 'customer@example.com']);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'customer@example.com',
            'password' => 'wrong-password',
            'device_name' => 'test-device',
        ])->assertUnauthorized()
            ->assertJsonPath('success', false);
    }

    public function test_invalid_customer_phone_returns_json_validation_error(): void
    {
        $this->postJson('/api/v1/auth/customer/request-otp', [
            'phone' => '123',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['phone'])
            ->assertJsonPath('errors.phone.0', 'Format nomor WhatsApp tidak valid.');
    }

    public function test_customer_can_verify_otp_and_receive_a_token(): void
    {
        $user = User::factory()->create([
            'phone' => '628123456789',
            'user_type' => 'customer',
            'status' => 'active',
        ]);

        config(['services.otp.expose_code' => true]);

        $requestOtp = $this->postJson('/api/v1/auth/customer/request-otp', [
            'phone' => '08123456789',
        ])->assertOk();

        $requestOtp->assertJsonStructure([
            'data' => ['challenge_id', 'otp', 'expires_at'],
        ]);

        $data = $requestOtp->json('data');

        $this->postJson('/api/v1/auth/customer/verify-otp', [
            'phone' => '08123456789',
            'challenge_id' => $data['challenge_id'],
            'otp' => $data['otp'],
            'device_name' => 'test-device',
        ])->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.id', $user->id)
            ->assertJsonPath('data.token_type', 'Bearer')
            ->assertJsonStructure(['data' => ['token']]);

        $this->assertDatabaseHas('otp_challenges', [
            'id' => $data['challenge_id'],
        ]);
    }

    public function test_authenticated_user_can_view_profile_and_logout(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        Sanctum::actingAs($user, ['customer-api']);

        $this->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.user.id', $user->id);

        $this->postJson('/api/v1/auth/logout')
            ->assertOk()
            ->assertJsonPath('success', true);
    }
}
