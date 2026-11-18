<?php

namespace Tests\Feature\Api\V1;

use App\Models\ExternalApiActivityLog;
use App\Services\ExternalApiActivityLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SecurityLoggingTest extends TestCase
{
    use RefreshDatabase;

    public function test_invalid_and_oversized_webhooks_do_not_create_database_logs(): void
    {
        $this->postJson('/api/v1/payments/midtrans/webhook', [])->assertUnprocessable();
        $this->postJson('/api/v1/payments/midtrans/webhook', ['padding' => str_repeat('x', 17000)])->assertStatus(413);
        $this->assertDatabaseCount('external_api_activity_logs', 0);
    }

    public function test_valid_webhook_retries_keep_one_durable_notification(): void
    {
        Queue::fake();
        config(['services.midtrans.server_key' => 'test-server-key']);
        $data = ['order_id' => 'AUDIT-1', 'status_code' => '200', 'gross_amount' => '10000.00', 'transaction_status' => 'pending'];
        $data['signature_key'] = hash('sha512', 'AUDIT-120010000.00test-server-key');
        foreach ([1, 2] as $attempt) {
            $this->postJson('/api/v1/payments/midtrans/webhook', $data)->assertOk();
        }
        $this->assertDatabaseCount('payment_notifications', 1);
        $this->assertDatabaseCount('external_api_activity_logs', 2);
        $this->assertSame('[REDACTED]', ExternalApiActivityLog::first()->request_payload['signature_key']);
    }

    public function test_forged_webhook_does_not_create_logs_notifications_or_jobs(): void
    {
        Queue::fake();
        config(['services.midtrans.server_key' => 'test-server-key']);
        $this->postJson('/api/v1/payments/midtrans/webhook', [
            'order_id' => 'FORGED', 'status_code' => '200', 'gross_amount' => '10000.00',
            'transaction_status' => 'settlement', 'signature_key' => str_repeat('0', 128),
        ])->assertForbidden()->assertJsonPath('code', 'INVALID_SIGNATURE');
        $this->assertDatabaseCount('external_api_activity_logs', 0);
        $this->assertDatabaseCount('payment_notifications', 0);
        Queue::assertNothingPushed();
    }

    public function test_invalid_retention_cannot_delete_logs(): void
    {
        app(ExternalApiActivityLogger::class)->start('shipping_rate', 'Retensi', 'POST', 'https://example.test/quote');
        foreach (['0', '-1', '3651', 'invalid'] as $days) {
            $this->artisan('logs:prune-external', ['--days' => $days])->assertFailed();
        }
        $this->assertDatabaseCount('external_api_activity_logs', 1);
    }

    public function test_logs_redact_names_coordinates_notes_and_expire_by_retention(): void
    {
        $log = app(ExternalApiActivityLogger::class)->start('shipping_rate', 'Audit', 'POST', 'https://example.test/quote', ['destination' => [
            'name' => 'Contoh Audit', 'address_note' => 'Data pribadi', 'latitude' => -7.3274, 'longitude' => 108.2207,
        ]]);
        foreach ($log->request_payload['destination'] as $value) {
            $this->assertSame('[REDACTED]', $value);
        }
        $log->update(['started_at' => now()->subDays(31)]);
        app(ExternalApiActivityLogger::class)->start('shipping_rate', 'Baru', 'POST', 'https://example.test/quote');
        $this->artisan('logs:prune-external --dry-run')->assertSuccessful();
        $this->assertDatabaseCount('external_api_activity_logs', 2);
        $this->artisan('logs:prune-external')->assertSuccessful();
        $this->assertDatabaseCount('external_api_activity_logs', 1);
    }

    public function test_historical_payloads_can_be_scrubbed_without_deleting_log_records(): void
    {
        $log = app(ExternalApiActivityLogger::class)->start('shipping_rate', 'Lama', 'POST', 'https://example.test/quote');
        $log->update(['request_payload' => ['destination' => ['name' => 'Data lama', 'latitude' => -7.32]]]);
        $this->artisan('logs:sanitize-external --dry-run')->assertSuccessful();
        $this->assertSame('Data lama', $log->fresh()->request_payload['destination']['name']);
        $this->artisan('logs:sanitize-external')->assertSuccessful();
        $this->assertSame('[REDACTED]', $log->fresh()->request_payload['destination']['name']);
        $this->assertSame('[REDACTED]', $log->fresh()->request_payload['destination']['latitude']);
        $this->assertDatabaseCount('external_api_activity_logs', 1);
    }
}
