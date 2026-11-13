<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\PaymentCommunicationLogs\Pages\ListPaymentCommunicationLogs;
use App\Filament\Resources\PaymentCommunicationLogs\PaymentCommunicationLogResource;
use App\Models\PaymentCommunicationLog;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PaymentCommunicationLogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('superadmin'));
        $admin = User::factory()->create(['user_type' => 'admin']);
        $admin->assignRole(Role::findOrCreate('super_admin', 'web'));
        $this->actingAs($admin);
        config(['services.midtrans.server_key' => 'DO-NOT-RENDER']);
    }

    private function log(array $attributes = []): PaymentCommunicationLog
    {
        return PaymentCommunicationLog::create(['environment' => 'sandbox', 'provider_order_id' => 'AUDIT-DIRECT', 'operation' => 'charge',
            'http_method' => 'POST', 'endpoint' => 'https://api.sandbox.midtrans.com/v2/charge', 'http_status' => 201, 'outcome' => 'succeeded',
            'requested_at' => now(), 'finished_at' => now(), 'duration_ms' => 25,
            'request_payload' => ['transaction_details' => ['gross_amount' => 93000], 'secret' => 'DO-NOT-RENDER'],
            'response_payload' => ['va_numbers' => [['bank' => 'bca', 'va_number' => '123456789']], 'signature_key' => 'DO-NOT-RENDER'], ...$attributes]);
    }

    public function test_superadmin_can_filter_and_open_sanitized_payloads_in_slide_over(): void
    {
        $log = $this->log();
        $other = $this->log(['environment' => 'production', 'operation' => 'status', 'outcome' => 'not_found', 'http_status' => 404]);
        $this->get(PaymentCommunicationLogResource::getUrl())->assertOk();
        $page = Livewire::test(ListPaymentCommunicationLogs::class)->assertCanSeeTableRecords([$log, $other])
            ->filterTable('environment', 'sandbox')->assertCanNotSeeTableRecords([$other])
            ->mountTableAction('view', $log);
        $html = $page->instance()->getSchema('mountedActionSchema0')->toHtml();
        $this->assertStringContainsString('Request ke Midtrans', $html);
        $this->assertStringContainsString('123456789', $html);
        $this->assertStringContainsString('gross_amount', $html);
        $this->assertStringNotContainsString('DO-NOT-RENDER', $html);
        $this->assertFalse(PaymentCommunicationLogResource::canCreate());
        $this->assertFalse(PaymentCommunicationLogResource::canEdit($log));
        $this->assertFalse(PaymentCommunicationLogResource::canDelete($log));
    }

    public function test_customers_sellers_and_inactive_superadmins_cannot_access_logs(): void
    {
        foreach (['customer', 'store_admin', 'inactive_superadmin'] as $type) {
            $user = User::factory()->create(['status' => $type === 'inactive_superadmin' ? 'inactive' : 'active']);
            if ($type !== 'customer') {
                $user->assignRole(Role::findOrCreate($type === 'store_admin' ? 'store_admin' : 'super_admin', 'web'));
            }
            $this->actingAs($user);
            $this->assertFalse(PaymentCommunicationLogResource::canAccess());
            $this->get(PaymentCommunicationLogResource::getUrl())->assertForbidden();
            Livewire::test(ListPaymentCommunicationLogs::class)->assertForbidden();
        }
    }

    public function test_non_json_response_is_escaped_as_code(): void
    {
        $log = $this->log(['response_payload' => null, 'response_body' => '<script>alert("audit")</script> DO-NOT-RENDER']);
        $page = Livewire::test(ListPaymentCommunicationLogs::class)->mountTableAction('view', $log);
        $html = $page->instance()->getSchema('mountedActionSchema0')->toHtml();
        $this->assertStringContainsString('Respons non-JSON', $html);
        $this->assertStringNotContainsString('<script>alert("audit")</script>', $html);
        $this->assertStringNotContainsString('DO-NOT-RENDER', $html);
    }

    public function test_filter_links_use_filament_query_string_alias(): void
    {
        $this->log(['provider_order_id' => 'FILTER-CHARGE']);
        $this->log(['provider_order_id' => 'FILTER-STATUS', 'operation' => 'status']);
        $this->get(PaymentCommunicationLogResource::getUrl('index', ['filters' => ['operation' => ['value' => 'charge']]]))
            ->assertOk()->assertSee('FILTER-CHARGE')->assertDontSee('FILTER-STATUS');
    }
}
