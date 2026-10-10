<?php

namespace Tests\Feature\Api\V1;

use App\Models\OtpChallenge;
use App\Models\User;
use App\Services\Auth\OtpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\PersonalAccessToken;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class CustomerOtpAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['queue.default' => 'sync']);
    }

    public function test_wrong_attempts_persist_and_five_failures_block_even_the_correct_code(): void
    {
        $this->createCustomer();
        $data = $this->requestOtp();
        $payload = $this->verificationPayload($data);

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->postJson('/api/v1/auth/customer/verify-otp', [
                ...$payload,
                'otp' => '000000',
            ])->assertUnprocessable()->assertJsonValidationErrors('otp');

            $this->assertDatabaseHas('otp_challenges', [
                'id' => $data['challenge_id'],
                'attempts' => $attempt,
            ]);
        }

        $this->postJson('/api/v1/auth/customer/verify-otp', $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('otp');
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_phone_format_variants_share_the_phone_request_limit(): void
    {
        $this->freezeSecond();
        $phones = [
            '08123456789',
            '+628123456789',
            '628123456789',
            '8123456789',
            '08123456789',
            '+628123456789',
            '628123456789',
            '8123456789',
            '08123456789',
            '+628123456789',
        ];

        foreach ($phones as $index => $phone) {
            $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.'.($index + 1)])
                ->postJson('/api/v1/auth/customer/request-otp', compact('phone'))
                ->assertOk();
        }

        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.11'])
            ->postJson('/api/v1/auth/customer/request-otp', ['phone' => '628123456789'])
            ->assertTooManyRequests()
            ->assertHeader('Retry-After', '60')
            ->assertExactJson([
                'success' => false,
                'message' => 'Terlalu banyak percobaan. Silakan tunggu sebelum mencoba lagi.',
                'retry_after' => 60,
            ]);

        $this->assertDatabaseCount('otp_challenges', 10);
        $this->travel(10)->seconds();
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.12'])
            ->post('/api/v1/auth/customer/request-otp', ['phone' => '08123456789'], [
                'Accept' => 'text/html',
            ])
            ->assertTooManyRequests()
            ->assertHeader('Content-Type', 'application/json')
            ->assertHeader('Retry-After', '50')
            ->assertJsonPath('retry_after', 50)
            ->assertJsonPath('success', false)
            ->assertJsonMissingPath('exception')
            ->assertJsonMissingPath('file')
            ->assertJsonMissingPath('trace');

        $this->travel(51)->seconds();
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.13'])
            ->postJson('/api/v1/auth/customer/request-otp', ['phone' => '08123456789'])
            ->assertOk();
        $this->assertDatabaseCount('otp_challenges', 11);
    }

    #[DataProvider('invalidInputProvider')]
    public function test_malformed_input_is_a_validation_error_not_a_server_error(
        string $endpoint,
        array $payload,
        string $field,
    ): void {
        $this->postJson('/api/v1/auth/'.$endpoint, $payload)
            ->assertUnprocessable()->assertJsonValidationErrors($field);

        $this->assertDatabaseCount('otp_challenges', 0);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public static function invalidInputProvider(): array
    {
        return [
            'request phone array' => ['customer/request-otp', ['phone' => ['08123456789']], 'phone'],
            'request phone number' => ['customer/request-otp', ['phone' => 628123456789], 'phone'],
            'letters inside phone' => ['customer/request-otp', ['phone' => '08abc123456789'], 'phone'],
            'verify phone array' => ['customer/verify-otp', ['phone' => ['08123456789']], 'phone'],
            'verify challenge array' => ['customer/verify-otp', ['challenge_id' => [1]], 'challenge_id'],
        ];
    }

    public function test_production_never_exposes_otp_even_if_flag_is_enabled(): void
    {
        config(['services.otp.expose_code' => true]);
        $previousEnvironment = $this->app['env'];
        $this->app['env'] = 'production';

        try {
            $this->postJson('/api/v1/auth/customer/request-otp', ['phone' => '08123456789'])
                ->assertOk()
                ->assertJsonStructure(['data' => ['challenge_id', 'expires_at']])
                ->assertJsonMissingPath('data.otp');
        } finally {
            $this->app['env'] = $previousEnvironment;
        }
    }

    public function test_otp_is_sent_through_maxchat_and_delivery_is_recorded(): void
    {
        config([
            'services.maxchat.enabled' => true,
            'services.maxchat.base_url' => 'https://core.maxchat.id/walatra-herbal',
            'services.maxchat.api_token' => 'test-maxchat-token',
            'services.otp.expose_code' => true,
        ]);
        Http::fake([
            'https://core.maxchat.id/walatra-herbal/api/send*' => Http::response([
                'id' => 'maxchat-message-123',
                'to' => '628123456789',
                'status' => 'sent',
            ], 200),
        ]);

        $response = $this->postJson('/api/v1/auth/customer/request-otp', [
            'phone' => '08123456789',
        ])->assertOk();

        Http::assertSent(function ($request): bool {
            return $request->method() === 'GET'
                && str_starts_with($request->url(), 'https://core.maxchat.id/walatra-herbal/api/send?')
                && $request->hasHeader('Authorization', 'Bearer test-maxchat-token')
                && $request['to'] === '628123456789'
                && $request['type'] === 'text'
                && str_contains($request['text'], 'Kode OTP Walatra Anda adalah');
        });

        $this->assertDatabaseHas('otp_challenges', [
            'id' => $response->json('data.challenge_id'),
            'delivery_status' => 'sent',
            'provider_message_id' => 'maxchat-message-123',
        ]);
    }

    public function test_pending_maxchat_message_is_checked_again_for_final_status(): void
    {
        config([
            'services.maxchat.enabled' => true,
            'services.maxchat.api_token' => 'test-maxchat-token',
            'services.otp.expose_code' => true,
        ]);
        Http::fake(function ($request) {
            if (str_contains($request->url(), '/api/send?')) {
                return Http::response([
                    'id' => 'maxchat-message-pending',
                    'to' => '628123456789',
                    'status' => 'pending',
                ], 200);
            }

            return Http::response([
                'data' => [
                    'id' => 'maxchat-message-pending',
                    'status' => 'delivered',
                ],
            ], 200);
        });

        $response = $this->postJson('/api/v1/auth/customer/request-otp', [
            'phone' => '08123456789',
        ])->assertOk()
            ->assertJsonPath('data.delivery_status', 'delivered');

        $this->assertDatabaseHas('otp_challenges', [
            'id' => $response->json('data.challenge_id'),
            'delivery_status' => 'delivered',
        ]);
    }

    public function test_maxchat_failure_invalidates_challenge_and_returns_safe_json_error(): void
    {
        config([
            'services.maxchat.enabled' => true,
            'services.maxchat.api_token' => 'test-maxchat-token',
        ]);
        Http::fake([
            'https://core.maxchat.id/walatra-herbal/api/send*' => Http::response([
                'statusCode' => 401,
                'error' => 'Unauthorized',
                'message' => 'Missing authentication',
            ], 401),
        ]);

        $this->postJson('/api/v1/auth/customer/request-otp', [
            'phone' => '08123456789',
        ])->assertOk()
            ->assertJsonPath('message', 'OTP gagal dikirim. Silakan coba lagi.')
            ->assertJsonPath('data.delivery_status', 'failed');

        $this->assertDatabaseHas('otp_challenges', [
            'phone' => '628123456789',
            'delivery_status' => 'failed',
        ]);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_maxchat_timeout_keeps_challenge_usable_because_message_may_have_been_sent(): void
    {
        config([
            'services.maxchat.enabled' => true,
            'services.maxchat.api_token' => 'test-maxchat-token',
            'services.otp.expose_code' => true,
        ]);
        Http::fake(function (): never {
            throw new ConnectionException('Simulated timeout after provider accepted request.');
        });

        $response = $this->postJson('/api/v1/auth/customer/request-otp', [
            'phone' => '08123456789',
        ])->assertOk()
            ->assertJsonPath('message', 'OTP sedang diproses. Silakan periksa WhatsApp Anda.')
            ->assertJsonPath('data.delivery_status', 'unknown');

        $challenge = OtpChallenge::findOrFail($response->json('data.challenge_id'));

        $this->assertNull($challenge->invalidated_at);
        $this->assertSame('unknown', $challenge->delivery_status);

        $this->postJson('/api/v1/auth/customer/verify-otp', [
            'phone' => '08123456789',
            'challenge_id' => $challenge->id,
            'otp' => $response->json('data.otp'),
            'device_name' => 'timeout-test',
        ])->assertOk();
    }

    public function test_account_disabled_after_login_cannot_access_profile_but_can_logout(): void
    {
        $user = $this->createCustomer();
        $data = $this->requestOtp();
        $token = $this->postJson('/api/v1/auth/customer/verify-otp', $this->verificationPayload($data))
            ->assertOk()->json('data.token');
        $headers = ['Authorization' => 'Bearer '.$token];

        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/auth/me', $headers)->assertOk();
        $user->update(['status' => 'inactive']);

        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/auth/me', $headers)->assertForbidden();

        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/auth/logout', [], $headers)->assertOk();
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_token_authenticates_profile_and_logout_revokes_only_that_device(): void
    {
        $user = $this->createCustomer();
        $otherToken = $user->createToken('other-device', ['customer-api']);
        $data = $this->requestOtp();
        $payload = $this->verificationPayload($data);

        $login = $this->postJson('/api/v1/auth/customer/verify-otp', $payload)
            ->assertOk()->assertJsonPath('data.user.id', $user->id);
        $this->assertNotNull(OtpChallenge::findOrFail($data['challenge_id'])->verified_at);
        $this->assertStringContainsString('no-store', $login->headers->get('Cache-Control'));

        $this->postJson('/api/v1/auth/customer/verify-otp', $payload)->assertUnprocessable();
        $this->assertDatabaseCount('personal_access_tokens', 2);

        $headers = ['Authorization' => 'Bearer '.$login->json('data.token')];
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/auth/me', $headers)
            ->assertOk()->assertJsonPath('data.user.phone', $user->phone);
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/auth/logout', [], $headers)->assertOk();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/auth/me', $headers)->assertUnauthorized();

        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/auth/me', [
            'Authorization' => 'Bearer '.$otherToken->plainTextToken,
        ])->assertOk();
        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    public function test_expired_token_cannot_access_profile(): void
    {
        config(['sanctum.expiration' => 43200]);
        $user = $this->createCustomer();
        $data = $this->requestOtp();
        $token = $this->postJson('/api/v1/auth/customer/verify-otp', $this->verificationPayload($data))
            ->assertOk()->json('data.token');
        $user->tokens()->update(['created_at' => now()->subDays(31)]);

        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/auth/me', ['Authorization' => 'Bearer '.$token])
            ->assertUnauthorized();
    }

    public function test_resend_invalidates_old_code_and_stores_only_a_hash(): void
    {
        $this->createCustomer();
        $old = $this->requestOtp();
        $this->travel(61)->seconds();
        $new = $this->requestOtp();
        $challenge = OtpChallenge::findOrFail($new['challenge_id']);

        $this->assertNotNull(OtpChallenge::findOrFail($old['challenge_id'])->invalidated_at);
        $this->assertNotSame($new['otp'], $challenge->code_hash);
        $this->assertTrue(Hash::check($new['otp'], $challenge->code_hash));
        $this->postJson('/api/v1/auth/customer/verify-otp', $this->verificationPayload($old))
            ->assertUnprocessable();
        $this->postJson('/api/v1/auth/customer/verify-otp', $this->verificationPayload($new))
            ->assertOk();
        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    public function test_otp_is_rejected_at_the_exact_expiration_time(): void
    {
        $this->createCustomer();
        $data = $this->requestOtp();
        $this->travelTo(OtpChallenge::findOrFail($data['challenge_id'])->expires_at);

        $this->postJson('/api/v1/auth/customer/verify-otp', $this->verificationPayload($data))
            ->assertUnprocessable()->assertJsonValidationErrors('otp');
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_challenge_is_bound_to_phone_and_purpose(): void
    {
        $this->createCustomer();
        $data = $this->requestOtp();

        $this->postJson('/api/v1/auth/customer/verify-otp', [
            ...$this->verificationPayload($data),
            'phone' => '08123456780',
        ])->assertUnprocessable();

        OtpChallenge::findOrFail($data['challenge_id'])->update(['purpose' => 'password_reset']);
        $this->postJson('/api/v1/auth/customer/verify-otp', $this->verificationPayload($data))
            ->assertUnprocessable();
        $this->assertDatabaseHas('otp_challenges', ['id' => $data['challenge_id'], 'attempts' => 0]);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_unregistered_customer_is_created_after_valid_otp(): void
    {
        $data = $this->requestOtp();
        $response = $this->postJson('/api/v1/auth/customer/verify-otp', $this->verificationPayload($data))
            ->assertOk()
            ->assertJsonPath('data.is_new_user', true)
            ->assertJsonPath('data.requires_profile_completion', true)
            ->assertJsonPath('data.user.phone', '628123456789');

        $this->assertDatabaseHas('users', [
            'id' => $response->json('data.user.id'),
            'phone' => '628123456789',
            'user_type' => 'customer',
            'status' => 'active',
            'profile_completed_at' => null,
        ]);
        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    public function test_existing_complete_customer_does_not_require_profile_completion(): void
    {
        $user = $this->createCustomer(['profile_completed_at' => now()]);
        $data = $this->requestOtp();

        $this->postJson('/api/v1/auth/customer/verify-otp', $this->verificationPayload($data))
            ->assertOk()
            ->assertJsonPath('data.user.id', $user->id)
            ->assertJsonPath('data.is_new_user', false)
            ->assertJsonPath('data.requires_profile_completion', false);
    }

    #[DataProvider('ineligibleCustomerProvider')]
    public function test_existing_inactive_or_internal_account_cannot_login_with_otp(array $attributes): void
    {
        $this->createCustomer($attributes);
        $data = $this->requestOtp();

        $this->postJson('/api/v1/auth/customer/verify-otp', $this->verificationPayload($data))
            ->assertUnprocessable()->assertJsonValidationErrors('phone');
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertDatabaseCount('users', 1);
    }

    public static function ineligibleCustomerProvider(): array
    {
        return [
            'inactive' => [['status' => 'inactive']],
            'internal account' => [['user_type' => 'admin']],
        ];
    }

    public function test_customer_can_complete_profile_after_otp_login(): void
    {
        $data = $this->requestOtp();
        $token = $this->postJson('/api/v1/auth/customer/verify-otp', $this->verificationPayload($data))
            ->assertOk()
            ->json('data.token');

        $this->patchJson('/api/v1/auth/customer/profile', ['name' => 'Customer Walatra'], [
            'Authorization' => 'Bearer '.$token,
        ])->assertOk()
            ->assertJsonPath('data.user.name', 'Customer Walatra')
            ->assertJsonPath('data.requires_profile_completion', false);

        $this->assertNotNull(User::query()->firstOrFail()->profile_completed_at);
    }

    public function test_disabled_exposure_still_returns_challenge_metadata(): void
    {
        config(['services.otp.expose_code' => false]);
        $response = $this->postJson('/api/v1/auth/customer/request-otp', ['phone' => '08123456789'])
            ->assertOk()
            ->assertJsonStructure(['data' => ['challenge_id', 'expires_at']])
            ->assertJsonMissingPath('data.otp')
            ->assertJsonMissingPath('data.code_hash');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    public function test_failed_resend_keeps_previous_challenge_usable(): void
    {
        $data = $this->requestOtp();
        Event::listen('eloquent.creating: '.OtpChallenge::class, function (): void {
            throw new RuntimeException('Simulated OTP storage failure');
        });

        try {
            app(OtpService::class)->issue('08123456789', 'customer_login', null, null);
            $this->fail('Issuing the OTP should have failed.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated OTP storage failure', $exception->getMessage());
        }

        $this->assertTrue(OtpChallenge::findOrFail($data['challenge_id'])->isUsable());
        $this->assertDatabaseCount('otp_challenges', 1);
    }

    public function test_token_creation_failure_does_not_consume_the_otp(): void
    {
        $this->createCustomer();
        $data = $this->requestOtp();
        Event::listen('eloquent.creating: '.PersonalAccessToken::class, function (): void {
            throw new RuntimeException('Simulated token storage failure');
        });

        try {
            app(OtpService::class)->verify('08123456789', $data['challenge_id'], $data['otp'], 'test-device');
            $this->fail('Creating the token should have failed.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated token storage failure', $exception->getMessage());
        }

        $this->assertTrue(OtpChallenge::findOrFail($data['challenge_id'])->isUsable());
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_verification_is_rate_limited_even_when_challenge_does_not_exist(): void
    {
        $this->freezeSecond();
        $payload = $this->verificationPayload(['challenge_id' => 999, 'otp' => '123456']);
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $this->postJson('/api/v1/auth/customer/verify-otp', $payload)->assertUnprocessable();
        }

        $this->postJson('/api/v1/auth/customer/verify-otp', $payload)
            ->assertTooManyRequests()
            ->assertHeader('Retry-After', '300')
            ->assertExactJson([
                'success' => false,
                'message' => 'Terlalu banyak percobaan. Silakan tunggu sebelum mencoba lagi.',
                'retry_after' => 300,
            ]);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_request_limit_by_ip_cannot_be_bypassed_by_changing_phone(): void
    {
        $this->freezeSecond();
        for ($number = 0; $number < 5; $number++) {
            $this->postJson('/api/v1/auth/customer/request-otp', [
                'phone' => '08123456'.str_pad((string) $number, 3, '0', STR_PAD_LEFT),
            ])->assertOk();
        }

        $this->postJson('/api/v1/auth/customer/request-otp', ['phone' => '08123456789'])
            ->assertTooManyRequests()
            ->assertHeader('Retry-After', '900')
            ->assertExactJson([
                'success' => false,
                'message' => 'Terlalu banyak percobaan. Silakan tunggu sebelum mencoba lagi.',
                'retry_after' => 900,
            ]);
        $this->assertDatabaseCount('otp_challenges', 5);
    }

    public function test_verification_limit_by_ip_cannot_be_bypassed_by_changing_challenge(): void
    {
        $this->freezeSecond();
        for ($id = 1; $id <= 30; $id++) {
            $this->postJson('/api/v1/auth/customer/verify-otp', $this->verificationPayload([
                'challenge_id' => $id,
                'otp' => '123456',
            ]))->assertUnprocessable();
        }

        $this->postJson('/api/v1/auth/customer/verify-otp', $this->verificationPayload([
            'challenge_id' => 31,
            'otp' => '123456',
        ]))->assertTooManyRequests()
            ->assertHeader('Retry-After', '300')
            ->assertExactJson([
                'success' => false,
                'message' => 'Terlalu banyak percobaan. Silakan tunggu sebelum mencoba lagi.',
                'retry_after' => 300,
            ]);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_email_login_is_not_an_authentication_path(): void
    {
        $this->postJson('/api/v1/auth/login', [
            'email' => 'missing@example.com',
            'password' => 'wrong-password',
            'device_name' => 'postman',
        ])->assertStatus(410)
            ->assertJsonPath('code', 'OTP_ONLY_AUTHENTICATION');

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    private function createCustomer(array $attributes = []): User
    {
        return User::factory()->create([
            'phone' => '628123456789',
            'email' => null,
            'password' => null,
            'user_type' => 'customer',
            'status' => 'active',
            ...$attributes,
        ]);
    }

    private function requestOtp(): array
    {
        config(['services.otp.expose_code' => true]);

        return $this->postJson('/api/v1/auth/customer/request-otp', ['phone' => '08123456789'])
            ->assertOk()->json('data');
    }

    private function verificationPayload(array $data): array
    {
        return [
            'phone' => '08123456789',
            'challenge_id' => $data['challenge_id'],
            'otp' => $data['otp'],
            'device_name' => 'postman',
        ];
    }
}
