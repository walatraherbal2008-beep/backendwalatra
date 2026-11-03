<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\Orders\Pages\ManageOrders;
use App\Models\Order;
use App\Models\User;
use App\Services\Marketplace\ManualOrderService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ManualOrderManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('superadmin'));
    }

    private function admin(): User
    {
        Role::findOrCreate('super_admin', 'web');
        $user = User::factory()->create(['user_type' => 'admin']);
        $user->assignRole('super_admin');

        return $user;
    }

    private function order(array $overrides = []): Order
    {
        return Order::create([
            'user_id' => User::factory()->create()->id,
            'order_number' => 'WAL-MANUAL-TEST', 'idempotency_key' => 'manual-panel-test',
            'status' => 'pending_payment', 'payment_provider' => 'manual', 'currency' => 'IDR',
            'subtotal' => 30000, 'shipping_gross_fee' => 10000, 'shipping_subsidy' => 0,
            'shipping_fee' => 10000, 'shipping_service' => 'MANUAL', 'total' => 40000,
            'shipping_address' => ['name' => 'Penerima Manual', 'phone' => '6289990001102', 'address' => 'Alamat Pesanan Manual', 'city' => 'Bandung'],
            'placed_at' => now(), ...$overrides,
        ]);
    }

    public function test_superadmin_can_open_orders_and_view_customer_address(): void
    {
        $this->actingAs($this->admin());
        $order = $this->order();
        $this->get(OrderResource::getUrl())->assertOk();
        Livewire::test(ManageOrders::class)->assertCanSeeTableRecords([$order])
            ->mountTableAction('view', $order)
            ->assertSchemaComponentStateSet('shipping_address.name', 'Penerima Manual', schema: 'mountedActionSchema0')
            ->assertSchemaComponentStateSet('shipping_address.address', 'Alamat Pesanan Manual', schema: 'mountedActionSchema0');
    }

    public function test_customer_cannot_open_order_management_or_confirm_payment(): void
    {
        $customer = User::factory()->create();
        $this->actingAs($customer)->get(OrderResource::getUrl())->assertForbidden();
        $this->expectException(HttpException::class);
        app(ManualOrderService::class)->confirmPaid($this->order(), $customer, 'Percobaan akses customer.');
    }

    public function test_order_table_filters_status_and_excludes_gateway_and_simulation_orders(): void
    {
        $this->actingAs($this->admin());
        $pending = $this->order();
        $paid = $this->order(['order_number' => 'WAL-PAID', 'status' => 'paid']);
        $gateway = $this->order(['order_number' => 'WAL-GATEWAY', 'payment_provider' => 'midtrans']);
        $simulation = $this->order(['order_number' => 'WAL-SIMULATION', 'payment_provider' => 'simulation']);
        Livewire::test(ManageOrders::class)->assertCanSeeTableRecords([$pending, $paid])
            ->assertCanNotSeeTableRecords([$gateway, $simulation])
            ->filterTable('status', 'paid')->assertCanSeeTableRecords([$paid])
            ->assertCanNotSeeTableRecords([$pending]);
    }

    public function test_confirmation_action_records_admin_reference_and_paid_time(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);
        $order = $this->order();
        Livewire::test(ManageOrders::class)->callTableAction('confirmPaid', $order, data: [
            'reference' => 'TRANSFER-001', 'note' => 'Dana sudah diterima dan stok tersedia.',
        ])->assertHasNoTableActionErrors();
        $this->assertSame('paid', $order->fresh()->status);
        $this->assertNotNull($order->fresh()->paid_at);
        $this->assertSame('TRANSFER-001', $order->fresh()->payment_reference);
        $this->assertDatabaseHas('order_status_histories', ['order_id' => $order->id, 'status' => 'paid', 'created_by' => $admin->id]);
    }

    public function test_confirmation_requires_verification_note(): void
    {
        $this->actingAs($this->admin());
        $order = $this->order();
        Livewire::test(ManageOrders::class)->callTableAction('confirmPaid', $order, data: ['note' => ''])
            ->assertHasTableActionErrors(['note' => 'required']);
        $this->assertSame('pending_payment', $order->fresh()->status);
    }

    public function test_cancellation_action_records_admin_and_reason(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);
        $order = $this->order();
        Livewire::test(ManageOrders::class)->callTableAction('cancelOrder', $order, data: ['note' => 'Stok tidak tersedia untuk pesanan ini.'])
            ->assertHasNoTableActionErrors();
        $this->assertSame('cancelled', $order->fresh()->status);
        $this->assertNull($order->fresh()->paid_at);
        $this->assertDatabaseHas('order_status_histories', ['order_id' => $order->id, 'status' => 'cancelled', 'created_by' => $admin->id]);
    }

    public function test_stale_confirmation_cannot_mark_cancelled_order_paid(): void
    {
        $admin = $this->admin();
        $order = $this->order();
        app(ManualOrderService::class)->cancel($order, $admin, 'Customer membatalkan pesanan.');
        try {
            app(ManualOrderService::class)->confirmPaid($order, $admin, 'Dana sudah diterima.');
            $this->fail('Cancelled order must not become paid.');
        } catch (ValidationException) {
            $this->assertSame('cancelled', $order->fresh()->status);
            $this->assertDatabaseCount('order_status_histories', 1);
        }
    }

    public function test_duplicate_confirmation_and_payment_of_non_manual_order_are_rejected(): void
    {
        $admin = $this->admin();
        $order = $this->order();
        app(ManualOrderService::class)->confirmPaid($order, $admin, 'Dana sudah diterima.');
        try {
            app(ManualOrderService::class)->confirmPaid($order, $admin, 'Duplikasi konfirmasi lunas.');
            $this->fail('Duplicate payment confirmation must be rejected.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('order_status_histories', 1);
        }
        $order->update(['status' => 'pending_payment', 'payment_provider' => 'midtrans']);
        $this->expectException(ValidationException::class);
        app(ManualOrderService::class)->confirmPaid($order, $admin, 'Tidak boleh mengubah pembayaran gateway.');
    }
}
