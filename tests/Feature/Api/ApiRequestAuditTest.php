<?php

namespace Tests\Feature\Api;

use App\Models\ApiRequestAuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ApiRequestAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_api_request_and_response_are_audited_with_a_request_id(): void
    {
        $response = $this->getJson('/api/v1/not-a-real-endpoint?search=private-value')
            ->assertNotFound()
            ->assertHeader('X-Request-ID');

        $log = ApiRequestAuditLog::query()->sole();

        $this->assertSame($response->headers->get('X-Request-ID'), $log->request_id);
        $this->assertSame('GET', $log->http_method);
        $this->assertSame(404, $log->status_code);
        $this->assertSame('guest', $log->authentication_status);
        $this->assertContains('search', $log->query_keys);
        $this->assertStringNotContainsString('private-value', json_encode($log->toArray()));
        $this->assertSame('NOT_FOUND', $log->response_payload['code']);
    }

    public function test_unauthenticated_api_access_is_recorded(): void
    {
        $this->getJson('/api/v1/cart')->assertUnauthorized();

        $log = ApiRequestAuditLog::query()->sole();
        $this->assertSame('api/v1/cart', $log->endpoint);
        $this->assertSame(401, $log->status_code);
        $this->assertNull($log->actor_id);
        $this->assertSame('guest', $log->authentication_status);
    }

    public function test_authenticated_customer_identity_is_attached_to_audit_record(): void
    {
        $user = User::factory()->create(['user_type' => 'customer', 'status' => 'active']);
        $token = $user->createToken('audit-test', ['customer-api'])->plainTextToken;

        $this->withToken($token)->getJson('/api/v1/cart')->assertOk();

        $log = ApiRequestAuditLog::query()->sole();
        $this->assertSame(User::class, $log->actor_type);
        $this->assertSame($user->id, $log->actor_id);
        $this->assertSame('authenticated', $log->authentication_status);
    }

    public function test_early_api_rejections_are_audited(): void
    {
        $this->postJson('/api/v1/payments/midtrans/webhook', ['padding' => str_repeat('x', 17000)])
            ->assertStatus(413);

        $log = ApiRequestAuditLog::query()->sole();
        $this->assertSame(413, $log->status_code);
        $this->assertSame('api/v1/payments/midtrans/webhook', $log->endpoint);
    }

    public function test_sensitive_request_values_are_redacted_without_changing_api_validation(): void
    {
        $response = $this->postJson('/api/v1/auth/customer/request-otp', [
            'phone' => 'not-a-valid-phone',
            'api_token' => 'private-token-value',
            'display_name' => 'Nama privat',
        ])->assertUnprocessable();

        $log = ApiRequestAuditLog::query()->sole();
        $this->assertSame($response->status(), $log->status_code);
        $this->assertSame('[REDACTED]', $log->request_payload['phone']);
        $this->assertSame('[REDACTED]', $log->request_payload['api_token']);
        $this->assertSame('[REDACTED]', $log->request_payload['display_name']);
        $this->assertStringNotContainsString('private-token-value', json_encode($log->request_payload));
    }

    public function test_non_api_requests_are_not_audited(): void
    {
        $this->get('/up')->assertOk();

        $this->assertDatabaseCount('api_request_audit_logs', 0);
    }

    public function test_audit_retention_supports_dry_run_and_pruning(): void
    {
        ApiRequestAuditLog::query()->create([
            'request_id' => (string) Str::uuid(), 'http_method' => 'GET', 'endpoint' => 'api/v1/old',
            'status_code' => 200, 'started_at' => now()->subDays(91),
        ]);
        ApiRequestAuditLog::query()->create([
            'request_id' => (string) Str::uuid(), 'http_method' => 'GET', 'endpoint' => 'api/v1/new',
            'status_code' => 200, 'started_at' => now(),
        ]);

        $this->artisan('logs:prune-api-audit --dry-run')->assertSuccessful();
        $this->assertDatabaseCount('api_request_audit_logs', 2);

        $this->artisan('logs:prune-api-audit')->assertSuccessful();
        $this->assertDatabaseCount('api_request_audit_logs', 1);
    }
}
