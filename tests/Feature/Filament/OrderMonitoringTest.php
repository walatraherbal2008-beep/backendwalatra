<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\Orders\Pages\ManageOrders;
use App\Filament\Resources\Orders\Widgets\OrderStats;
use App\Filament\Resources\PaymentCommunicationLogs\PaymentCommunicationLogResource;
use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\PaymentCommunicationLog;
use App\Models\Store;
use App\Models\StoreBundleListing;
use App\Models\StoreProductListing;
use App\Models\User;
use App\Services\Marketplace\StoreTransactionReport;
use Filament\Actions\Action;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class OrderMonitoringTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('superadmin'));
        Role::findOrCreate('super_admin', 'web');
        $admin = User::factory()->create(['user_type' => 'admin']);
        $admin->assignRole('super_admin');
        $this->actingAs($admin);
        config(['services.midtrans.server_key' => 'sandbox-test-key', 'services.midtrans.is_production' => false]);
        Http::preventStrayRequests();
    }

    private function order(array $attributes = []): Order
    {
        $order = Order::create([
            'user_id' => User::factory()->create(['name' => 'Customer Monitor'])->id,
            'order_number' => 'MON-'.Str::random(12), 'idempotency_key' => Str::uuid()->toString(),
            'status' => 'pending_payment', 'payment_provider' => 'manual', 'currency' => 'IDR',
            'subtotal' => 30000, 'shipping_fee' => 5000, 'total' => 35000, 'stock_mode' => 'unlimited',
            'shipping_address' => ['name' => 'Penerima Monitor', 'phone' => '081234567890', 'address' => 'Jalan Monitor'],
            'payment_due_at' => now()->addDay(), ...$attributes,
        ]);
        if (isset($attributes['created_at'])) {
            $order->forceFill(['created_at' => $attributes['created_at']->copy()->timezone(config('app.timezone'))])->save();
        }

        return $order;
    }

    private function item(Order $order, Store $store, int $subtotal, bool $bundle = false): Model
    {
        $listing = $bundle
            ? StoreBundleListing::factory()->create(['store_id' => $store->id, 'status' => 'inactive'])
            : StoreProductListing::factory()->create(['store_id' => $store->id, 'status' => 'inactive']);
        $order->items()->create([
            'item_type' => $bundle ? 'bundle' : 'product',
            $bundle ? 'store_bundle_listing_id' : 'store_product_listing_id' => $listing->id,
            'sku' => 'MON-SKU', 'product_name' => $bundle ? 'Paket Monitor' : 'Produk Monitor',
            'variant_name' => 'Varian Monitor', 'store_name' => $store->name,
            'quantity' => 1, 'unit_price' => $subtotal, 'subtotal' => $subtotal,
            'bundle_components' => $bundle ? [['product_name' => 'Komponen Monitor', 'variant_name' => 'Isi', 'sku' => 'COMP', 'quantity' => 2]] : null,
        ]);

        return $listing;
    }

    private function payment(Order $order, array $attributes = []): OrderPayment
    {
        $number = $order->payments()->count() + 1;
        $payment = $order->payments()->create([
            'attempt_number' => $number, 'provider_order_id' => $order->order_number.'-P'.str_pad((string) $number, 6, '0', STR_PAD_LEFT),
            'transaction_id' => 'trx-'.Str::uuid(), 'method' => 'bca_va', 'environment' => 'sandbox',
            'idempotency_key' => 'attempt-'.$number, 'state' => 'pending', 'amount' => $order->total,
            'currency' => 'IDR', 'request_payload' => ['secret' => 'DO-NOT-RENDER'],
            'response_payload' => ['signature_key' => 'DO-NOT-RENDER'], 'expires_at' => now()->addHours(6), ...$attributes,
        ]);
        $order->update(['active_payment_id' => $payment->id]);

        return $payment;
    }

    private function summary($page): array
    {
        return StoreTransactionReport::summary($page->instance()->getFilteredTableQuery(), $page->instance()->selectedStoreId());
    }

    public function test_superadmin_can_open_resource_and_it_exposes_no_write_pages_or_actions(): void
    {
        $order = $this->order();
        $this->get(OrderResource::getUrl())->assertOk();
        Livewire::test(ManageOrders::class)->assertCanSeeTableRecords([$order])
            ->assertTableActionDoesNotExist('edit')->assertTableActionDoesNotExist('delete')
            ->assertTableActionExists('confirmPaid')->assertTableActionExists('cancelOrder');
        $this->assertFalse(OrderResource::canCreate());
        $this->assertFalse(OrderResource::canEdit($order));
        $this->assertFalse(OrderResource::canDelete($order));
        $this->assertFalse(OrderResource::canDeleteAny());
        $this->assertCount(1, OrderResource::getPages());
    }

    public function test_customers_store_admins_and_inactive_superadmins_cannot_access_monitoring(): void
    {
        $url = OrderResource::getUrl();
        foreach (['customer', 'store_admin', 'inactive_superadmin'] as $type) {
            $user = User::factory()->create(['status' => $type === 'inactive_superadmin' ? 'inactive' : 'active']);
            if ($type !== 'customer') {
                $role = $type === 'store_admin' ? 'store_admin' : 'super_admin';
                Role::findOrCreate($role, 'web');
                $user->assignRole($role);
            }
            $this->actingAs($user)->get($url)->assertForbidden();
            $this->assertFalse(OrderResource::canAccess());
            $this->assertFalse(OrderStats::canView());
        }
    }

    public function test_orders_from_all_providers_are_shown_newest_first(): void
    {
        $old = $this->order(['created_at' => now()->subDay()]);
        $new = $this->order(['payment_provider' => 'midtrans', 'created_at' => now()]);
        $simulation = $this->order(['payment_provider' => 'simulation']);
        Livewire::test(ManageOrders::class)->assertCanSeeTableRecords([$new, $old], inOrder: true)
            ->assertCanSeeTableRecords([$simulation]);
    }

    public function test_store_filter_combines_products_bundles_and_keeps_one_row_per_order(): void
    {
        $a = Store::factory()->create();
        $b = Store::factory()->create();
        $order = $this->order(['status' => 'paid', 'payment_provider' => 'midtrans']);
        $this->item($order, $a, 10000);
        $this->item($order, $a, 5000, true);
        $this->item($order, $b, 15000);
        $this->payment($order);
        $this->payment($order, ['state' => 'cancelled']);
        $other = $this->order();
        $this->item($other, $b, 30000, true);
        $page = Livewire::test(ManageOrders::class)->filterTable('store', $a->id)
            ->assertCanSeeTableRecords([$order])->assertCanNotSeeTableRecords([$other])->assertCountTableRecords(1);
        $this->assertEquals(15000, $page->instance()->getTableRecords()->first()->store_subtotal);
        $this->assertEquals(35000, $page->instance()->getTableRecords()->first()->total);
        $this->assertEquals(15000, $this->summary($page)['paid_amount']);
        $this->assertSame(1, $this->summary($page)['paid']);
        $page->filterTable('store', $b->id)->assertCountTableRecords(2);
        $this->assertEquals(15000, $this->summary($page)['paid_amount']);
    }

    public function test_historical_store_names_and_deleted_listings_remain_visible_for_inactive_stores(): void
    {
        $store = Store::factory()->create(['name' => 'Nama Awal']);
        $order = $this->order();
        $product = $this->item($order, $store, 20000);
        $bundle = $this->item($order, $store, 10000, true);
        $product->delete();
        $bundle->delete();
        $store->update(['name' => 'Nama Baru', 'status' => 'inactive']);
        $page = Livewire::test(ManageOrders::class)->filterTable('store', $store->id)
            ->assertCanSeeTableRecords([$order])->assertSee('Nama Awal')
            ->mountTableAction('view', $order);
        $detail = $page->instance()->getSchema('mountedActionSchema0')->toHtml();
        $this->assertStringContainsString('Barang toko terpilih', $detail);
        $this->assertStringContainsString('Komponen Monitor', $detail);
        $record = $page->instance()->getTableRecords()->first();
        $this->assertEquals(30000, $record->store_subtotal);
        $this->assertCount(2, StoreTransactionReport::selectedItems($record, $store->id));
    }

    public function test_order_without_store_relations_is_not_lost_from_general_monitoring(): void
    {
        $order = $this->order();
        $order->items()->create(['item_type' => 'bundle', 'sku' => 'OLD', 'product_name' => 'Old', 'variant_name' => 'Old',
            'store_name' => 'Toko Riwayat', 'quantity' => 1, 'unit_price' => 30000, 'subtotal' => 30000]);
        $store = Store::factory()->create();
        Livewire::test(ManageOrders::class)->assertCanSeeTableRecords([$order])->assertSee('Toko Riwayat')
            ->filterTable('store', $store->id)->assertCanNotSeeTableRecords([$order]);
    }

    public function test_filters_and_search_drive_summary_without_counting_attempts_twice(): void
    {
        $paid = $this->order(['status' => 'paid', 'payment_provider' => 'midtrans', 'order_number' => 'MON-SEARCH']);
        $this->payment($paid);
        $this->payment($paid, ['state' => 'cancelled']);
        $this->order();
        $this->order(['status' => 'payment_review']);
        $this->order(['status' => 'refunded']);
        $page = Livewire::test(ManageOrders::class);
        $summary = $this->summary($page);
        $this->assertSame(['total' => 4, 'pending' => 1, 'paid' => 1, 'review' => 1], array_diff_key($summary, ['paid_amount' => 0]));
        $this->assertEquals(35000, $summary['paid_amount']);
        $page->filterTable('payment_provider', 'midtrans')->assertCountTableRecords(1);
        $this->assertSame(1, $this->summary($page)['total']);
        $page->filterTable('status', 'paid')->searchTable('MON-SEARCH')->assertCanSeeTableRecords([$paid]);
        $this->assertSame(1, $this->summary($page)['paid']);
        $page->searchTable('not-found')->assertCountTableRecords(0);
        $this->assertSame(0, $this->summary($page)['total']);
    }

    public function test_search_matches_customer_name_and_phone(): void
    {
        $order = $this->order();
        $other = $this->order();
        $order->user->update(['name' => 'Nama Unik Monitoring', 'phone' => '6289876543210']);
        Livewire::test(ManageOrders::class)->searchTable('Nama Unik Monitoring')
            ->assertCanSeeTableRecords([$order])->assertCanNotSeeTableRecords([$other])
            ->searchTable('6289876543210')->assertCanSeeTableRecords([$order])->assertCanNotSeeTableRecords([$other]);
    }

    public function test_environment_comes_from_active_payment_not_current_server_configuration(): void
    {
        $sandbox = $this->order(['payment_provider' => 'midtrans']);
        $this->payment($sandbox);
        $production = $this->order(['payment_provider' => 'midtrans']);
        $this->payment($production, ['environment' => 'production']);
        $unknown = $this->order(['payment_provider' => 'midtrans']);
        $manual = $this->order();
        $page = Livewire::test(ManageOrders::class)->assertSee('Belum diketahui')->assertSee('Production')
            ->filterTable('environment', 'production')->assertCanSeeTableRecords([$production])
            ->assertCanNotSeeTableRecords([$sandbox, $unknown, $manual]);
        $page->filterTable('environment', 'unknown')->assertCanSeeTableRecords([$unknown])->assertCountTableRecords(1);
        $page->filterTable('environment', 'sandbox')->assertCanSeeTableRecords([$sandbox])->assertCountTableRecords(1);
    }

    public function test_date_filter_uses_inclusive_jakarta_calendar_day(): void
    {
        $before = $this->order(['created_at' => Carbon::parse('2026-11-08 16:59:59', 'UTC')]);
        $start = $this->order(['created_at' => Carbon::parse('2026-11-08 17:00:00', 'UTC')]);
        $end = $this->order(['created_at' => Carbon::parse('2026-11-09 16:59:59', 'UTC')]);
        $after = $this->order(['created_at' => Carbon::parse('2026-11-09 17:00:00', 'UTC')]);
        $page = Livewire::test(ManageOrders::class)->filterTable('created_at', ['from' => '2026-11-09', 'until' => '2026-11-09'])
            ->assertCanSeeTableRecords([$start, $end])->assertCanNotSeeTableRecords([$before, $after])->assertCountTableRecords(2);
        $this->assertSame(2, $this->summary($page)['total']);
    }

    public function test_slide_over_shows_complete_order_and_sanitized_payment_payloads(): void
    {
        $a = Store::factory()->create(['name' => 'Toko A']);
        $b = Store::factory()->create(['name' => 'Toko B']);
        $order = $this->order(['payment_provider' => 'midtrans']);
        $this->item($order, $a, 10000);
        $this->item($order, $b, 20000, true);
        $this->payment($order);
        $this->payment($order, ['state' => 'expired']);
        $page = Livewire::test(ManageOrders::class)->filterTable('store', $a->id)->mountTableAction('view', $order)
            ->assertSchemaComponentStateSet('shipping_address.address', 'Jalan Monitor', schema: 'mountedActionSchema0');
        $detail = $page->instance()->getSchema('mountedActionSchema0')->toHtml();
        foreach (['Toko A', 'Toko B', 'Seluruh barang order', 'Penerima Monitor', 'Seluruh percobaan pembayaran'] as $text) {
            $this->assertStringContainsString($text, $detail);
        }
        $this->assertStringContainsString('Request ke Midtrans', $detail);
        $this->assertStringContainsString('Respons Midtrans', $detail);
        $this->assertStringContainsString('Lihat log komunikasi Midtrans', $detail);
        $this->assertStringNotContainsString('DO-NOT-RENDER', $detail);
    }

    public function test_stats_widget_uses_the_same_store_filter_and_search(): void
    {
        $store = Store::factory()->create();
        $paid = $this->order(['status' => 'paid', 'order_number' => 'MON-WIDGET']);
        $this->item($paid, $store, 12000);
        $this->order();
        $widget = Livewire::test(OrderStats::class, ['tableFilters' => ['store' => ['value' => (string) $store->id]], 'tableSearch' => 'MON-WIDGET'])
            ->assertSee('Nilai barang toko lunas');
        $stats = (new \ReflectionMethod(OrderStats::class, 'getStats'))->invoke($widget->instance());
        $this->assertSame(1, $stats[0]->getValue());
        $this->assertSame(1, $stats[2]->getValue());
        $this->assertStringContainsString('12.000', $stats[2]->getDescription());
    }

    public function test_sync_verifies_status_without_creating_a_charge_and_refreshes_monitoring(): void
    {
        $order = $this->order(['payment_provider' => 'midtrans', 'payment_integration' => 'core_api']);
        $payment = $this->payment($order);
        Http::fake(['*/status' => Http::response([
            'order_id' => $payment->provider_order_id, 'transaction_id' => $payment->transaction_id,
            'gross_amount' => '35000.00', 'currency' => 'IDR', 'status_code' => '200', 'transaction_status' => 'settlement',
            'payment_type' => 'bank_transfer', 'fraud_status' => 'accept', 'settlement_time' => now('Asia/Jakarta')->format('Y-m-d H:i:s'),
        ])]);
        $page = Livewire::test(ManageOrders::class)->callTableAction('syncPayment', $order)
            ->assertNotified('Status pembayaran berhasil disinkronkan')->assertDispatched('orders-monitoring-refreshed');
        $this->assertSame('paid', $order->fresh()->status);
        $this->assertSame(1, $this->summary($page)['paid']);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/status'));
        $this->assertDatabaseCount('order_payments', 1);
    }

    public function test_sync_inside_slide_over_updates_open_detail_and_keeps_store_filter(): void
    {
        $store = Store::factory()->create();
        $order = $this->order(['payment_provider' => 'midtrans', 'payment_integration' => 'core_api']);
        $this->item($order, $store, 30000);
        $payment = $this->payment($order);
        Http::fake(['*/status' => Http::response([
            'order_id' => $payment->provider_order_id, 'transaction_id' => $payment->transaction_id,
            'gross_amount' => '35000.00', 'currency' => 'IDR', 'status_code' => '200', 'transaction_status' => 'settlement',
            'payment_type' => 'bank_transfer', 'fraud_status' => 'accept', 'settlement_time' => now('Asia/Jakarta')->format('Y-m-d H:i:s'),
        ])]);
        $page = Livewire::test(ManageOrders::class)->filterTable('store', $store->id)
            ->callAction([TestAction::make('view')->table($order), TestAction::make('syncPayment')])
            ->assertNotified('Status pembayaran berhasil disinkronkan')
            ->assertSchemaComponentStateSet('status', 'paid', schema: 'mountedActionSchema0');
        $this->assertSame($store->id, $page->instance()->selectedStoreId());
        $this->assertSame(1, $this->summary($page)['paid']);
        $this->assertEquals(30000, $this->summary($page)['paid_amount']);
        Http::assertSentCount(1);
        $this->assertDatabaseCount('order_payments', 1);
    }

    public function test_sync_reports_provider_failure_or_busy_operation_and_preserves_filters(): void
    {
        $store = Store::factory()->create();
        $order = $this->order(['payment_provider' => 'midtrans', 'payment_integration' => 'core_api']);
        $this->item($order, $store, 30000);
        $this->payment($order);
        Http::fake(['*' => Http::response([], 503)]);
        $page = Livewire::test(ManageOrders::class)->filterTable('store', $store->id)
            ->callTableAction('syncPayment', $order)->assertNotified('Midtrans belum dapat dikonfirmasi');
        $this->assertSame($store->id, $page->instance()->selectedStoreId());
        $order->update(['payment_operation_token' => Str::uuid()->toString(), 'payment_operation_started_at' => now()]);
        $page->callTableAction('syncPayment', $order)->assertNotified('Pembayaran sedang diproses');
        $this->assertSame('pending_payment', $order->fresh()->status);
        $this->assertDatabaseCount('order_payments', 1);
        Http::assertSentCount(1);
    }

    public function test_provider_connection_timeout_shows_notification_without_creating_an_attempt(): void
    {
        $order = $this->order(['payment_provider' => 'midtrans', 'payment_integration' => 'core_api']);
        $this->payment($order);
        Http::fake(['*/status' => Http::failedConnection()]);
        Livewire::test(ManageOrders::class)->callTableAction('syncPayment', $order)
            ->assertNotified('Midtrans belum dapat dikonfirmasi');
        $this->assertSame('pending_payment', $order->fresh()->status);
        $this->assertNull($order->fresh()->payment_operation_token);
        $this->assertDatabaseCount('order_payments', 1);
    }

    public function test_report_eager_loads_relations_with_constant_query_count_for_multiple_orders(): void
    {
        $store = Store::factory()->create();
        for ($i = 0; $i < 3; $i++) {
            $order = $this->order(['payment_provider' => 'midtrans']);
            $this->item($order, $store, 10000);
            $this->item($order, $store, 20000, true);
            $this->payment($order);
            $order->statusHistories()->create(['status' => 'pending_payment', 'note' => 'Tes', 'created_by' => auth()->id()]);
        }
        DB::enableQueryLog();
        try {
            DB::flushQueryLog();
            StoreTransactionReport::query()->orderBy('id')->limit(1)->get();
            $singleCount = count(DB::getQueryLog());
            DB::flushQueryLog();
            $orders = StoreTransactionReport::query()->orderBy('id')->limit(3)->get();
            $multipleCount = count(DB::getQueryLog());
            $this->assertSame($singleCount, $multipleCount);
            foreach ($orders as $order) {
                $this->assertSame('sandbox', StoreTransactionReport::environment($order));
                $this->assertCount(2, StoreTransactionReport::selectedItems($order, $store->id));
                $this->assertNotNull($order->user->name);
                $this->assertFalse($order->relationLoaded('statusHistories'));
                $this->assertFalse($order->relationLoaded('payments'));
            }
            $this->assertSame($multipleCount, count(DB::getQueryLog()));
        } finally {
            DB::disableQueryLog();
        }
    }

    public function test_manual_orders_hide_sync_and_action_rechecks_authorization(): void
    {
        $manual = $this->order();
        Livewire::test(ManageOrders::class)->assertTableActionHidden('syncPayment', $manual);
        $order = $this->order(['payment_provider' => 'midtrans']);
        $page = Livewire::test(ManageOrders::class);
        auth()->user()->update(['status' => 'inactive']);
        $page->call('mountAction', 'syncPayment', [], ['table' => true, 'recordKey' => (string) $order->id])->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_superadmin_has_one_order_resource_and_legacy_url_redirects_with_filters(): void
    {
        $resources = array_filter(Filament::getPanel('superadmin')->getResources(), fn (string $resource): bool => $resource::getModel() === Order::class);
        $this->assertSame([OrderResource::class], array_values($resources));
        $parameters = ['search' => 'MON-LEGACY', 'filters' => ['store' => ['value' => '1']], 'sort' => 'created_at:desc'];
        $this->get('/superadmin/transaksi-toko?'.http_build_query($parameters))
            ->assertRedirect(OrderResource::getUrl('index', $parameters, panel: 'superadmin'));
        $this->actingAs(User::factory()->create())->get('/superadmin/transaksi-toko')->assertForbidden();
        auth()->logout();
        $this->get('/superadmin/transaksi-toko')->assertRedirect(Filament::getPanel('superadmin')->getLoginUrl());
    }

    public function test_all_providers_and_statuses_are_available_without_a_default_source_restriction(): void
    {
        $manual = $this->order(['payment_provider' => 'manual', 'status' => 'paid']);
        $sandbox = $this->order(['payment_provider' => 'midtrans', 'status' => 'paid']);
        $this->payment($sandbox);
        $simulation = $this->order(['payment_provider' => 'simulation', 'status' => 'paid']);
        $external = $this->order(['payment_provider' => 'external_import', 'status' => 'processing']);
        $page = Livewire::test(ManageOrders::class)->assertCanSeeTableRecords([$manual, $sandbox, $simulation, $external]);
        $this->assertSame(4, $this->summary($page)['total']);
        $this->assertEquals(105000, $this->summary($page)['paid_amount']);
        $this->assertArrayHasKey('external_import', StoreTransactionReport::providerOptions());
        $this->assertArrayHasKey('processing', StoreTransactionReport::statusOptions());
        $page->filterTable('payment_provider', 'external_import')->assertCountTableRecords(1)->assertCanSeeTableRecords([$external]);
        $page->filterTable('payment_provider', 'simulation')->assertCanSeeTableRecords([$simulation])->assertCountTableRecords(1);
    }

    public function test_environment_filter_supports_simulation_manual_unknown_and_both_midtrans_environments(): void
    {
        $manual = $this->order();
        $simulation = $this->order(['payment_provider' => 'simulation']);
        $sandbox = $this->order(['payment_provider' => 'midtrans']);
        $this->payment($sandbox);
        $production = $this->order(['payment_provider' => 'midtrans']);
        $this->payment($production, ['environment' => 'production']);
        $unknown = $this->order(['payment_provider' => 'external_import']);
        $page = Livewire::test(ManageOrders::class);
        foreach (['manual' => $manual, 'simulation' => $simulation, 'sandbox' => $sandbox, 'production' => $production, 'unknown' => $unknown] as $environment => $order) {
            $page->filterTable('environment', $environment)->assertCanSeeTableRecords([$order])->assertCountTableRecords(1);
        }
    }

    public function test_monitoring_refresh_polls_even_when_empty_and_detects_new_orders_without_gateway_calls(): void
    {
        $page = Livewire::test(ManageOrders::class)->searchTable('MON-REALTIME-NEW')->assertCountTableRecords(0)
            ->assertSeeHtml('wire:poll.5s="refreshMonitoring"');
        $order = $this->order(['order_number' => 'MON-REALTIME-NEW', 'payment_provider' => 'simulation']);
        $page->call('refreshMonitoring')->assertCanSeeTableRecords([$order])->assertCountTableRecords(1)
            ->assertDispatched('orders-monitoring-refreshed');
        $this->assertSame('MON-REALTIME-NEW', $page->get('tableSearch'));
        $this->assertSame(1, $this->summary($page)['total']);
        Http::assertNothingSent();
    }

    public function test_monitoring_refresh_updates_open_details_without_losing_filters_or_typed_notes(): void
    {
        $store = Store::factory()->create();
        $order = $this->order();
        $this->item($order, $store, 30000);
        $page = Livewire::test(ManageOrders::class)->filterTable('store', $store->id)->mountTableAction('view', $order)
            ->assertSchemaComponentStateSet('status', 'pending_payment', schema: 'mountedActionSchema0');
        $order->update(['status' => 'paid', 'paid_at' => now()]);
        $page->call('refreshMonitoring')->assertSchemaComponentStateSet('status', 'paid', schema: 'mountedActionSchema0');
        $this->assertSame($store->id, $page->instance()->selectedStoreId());
        $page->unmountAction();
        $order->update(['status' => 'pending_payment', 'paid_at' => null]);
        $page->mountTableAction('confirmPaid', $order)->set('mountedActions.0.data.note', 'Catatan sedang diketik')
            ->call('refreshMonitoring')->assertSet('mountedActions.0.data.note', 'Catatan sedang diketik');
        Http::assertNothingSent();
    }

    public function test_widget_summary_refreshes_after_new_database_changes_and_no_money_actions_exist_for_unknown_providers(): void
    {
        $widget = Livewire::test(OrderStats::class);
        $this->order(['payment_provider' => 'simulation', 'status' => 'paid']);
        $widget->dispatch('orders-monitoring-refreshed');
        $stats = (new \ReflectionMethod(OrderStats::class, 'getStats'))->invoke($widget->instance());
        $this->assertSame(1, $stats[0]->getValue());
        $this->assertSame(1, $stats[2]->getValue());
        $unknown = $this->order(['payment_provider' => 'external_import']);
        Livewire::test(ManageOrders::class)->assertTableActionHidden('confirmPaid', $unknown)
            ->assertTableActionHidden('cancelOrder', $unknown)->assertTableActionHidden('syncPayment', $unknown);
    }

    public function test_midtrans_cancellation_keeps_one_history_entry_with_admin_reason(): void
    {
        $order = $this->order(['payment_provider' => 'midtrans', 'payment_integration' => 'core_api']);
        $payment = $this->payment($order);
        $status = 'pending';
        Http::fake(function ($request) use ($payment, &$status) {
            if (str_ends_with($request->url(), '/cancel')) {
                $status = 'cancel';
            }

            return Http::response([
                'order_id' => $payment->provider_order_id, 'transaction_id' => $payment->transaction_id,
                'gross_amount' => '35000.00', 'currency' => 'IDR', 'status_code' => $status === 'cancel' ? '202' : '201',
                'transaction_status' => $status, 'payment_type' => 'bank_transfer',
                'va_numbers' => [['bank' => 'bca', 'va_number' => '123456789']],
            ]);
        });
        Livewire::test(ManageOrders::class)->callTableAction('cancelOrder', $order, data: ['note' => 'Dibatalkan untuk pemeriksaan admin'])
            ->assertNotified('Pesanan dibatalkan');
        $this->assertSame('cancelled', $order->fresh()->status);
        $this->assertSame(1, $order->statusHistories()->where('status', 'cancelled')->count());
        $this->assertDatabaseHas('order_status_histories', ['order_id' => $order->id, 'created_by' => auth()->id(), 'note' => 'Pembatalan oleh superadmin: Dibatalkan untuk pemeriksaan admin']);
        Http::assertNotSent(fn ($request): bool => str_ends_with($request->url(), '/charge'));
    }

    public function test_unconfirmed_midtrans_cancel_does_not_report_success_or_record_cancelled_history(): void
    {
        $order = $this->order(['payment_provider' => 'midtrans', 'payment_integration' => 'core_api']);
        $payment = $this->payment($order);
        Http::fake(['*' => Http::response([
            'order_id' => $payment->provider_order_id, 'transaction_id' => $payment->transaction_id,
            'gross_amount' => '35000.00', 'currency' => 'IDR', 'status_code' => '201', 'transaction_status' => 'pending',
            'payment_type' => 'bank_transfer', 'va_numbers' => [['bank' => 'bca', 'va_number' => '123456789']],
        ])]);
        Livewire::test(ManageOrders::class)->callTableAction('cancelOrder', $order, data: ['note' => 'Pembatalan belum dikonfirmasi'])
            ->assertNotified('Pembatalan sedang diproses');
        $this->assertSame('pending_payment', $order->fresh()->status);
        $this->assertSame(0, $order->statusHistories()->where('status', 'cancelled')->count());
    }

    public function test_open_detail_remains_available_after_order_leaves_the_selected_status_filter(): void
    {
        $order = $this->order();
        $page = Livewire::test(ManageOrders::class)->filterTable('status', 'pending_payment')->mountTableAction('view', $order);
        $order->update(['status' => 'paid', 'paid_at' => now()]);
        $page->call('refreshMonitoring')->assertCountTableRecords(0)
            ->assertSchemaComponentStateSet('status', 'paid', schema: 'mountedActionSchema0');
        $this->assertSame('pending_payment', $page->get('tableFilters')['status']['value']);
        Http::assertNothingSent();
    }

    public function test_monitoring_poll_rechecks_superadmin_status_before_reading_updates(): void
    {
        $page = Livewire::test(ManageOrders::class);
        auth()->user()->update(['status' => 'inactive']);
        $page->call('refreshMonitoring')->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_order_action_opens_only_its_communication_logs_without_sending_payment_requests(): void
    {
        $first = $this->order();
        $second = $this->order();
        foreach ([[$first, 'FIRST-ORDER-LOG'], [$second, 'SECOND-ORDER-LOG']] as [$order, $reference]) {
            PaymentCommunicationLog::create(['order_id' => $order->id, 'environment' => 'sandbox', 'operation' => 'status',
                'http_method' => 'GET', 'endpoint' => 'https://api.sandbox.midtrans.com/v2/'.$reference.'/status',
                'provider_order_id' => $reference, 'outcome' => 'succeeded', 'http_status' => 200, 'requested_at' => now()]);
        }
        $url = PaymentCommunicationLogResource::getUrl('index', ['filters' => ['order' => ['value' => $first->id]]]);
        Livewire::test(ManageOrders::class)
            ->assertTableActionExists('viewPaymentLogs', fn (Action $action): bool => $action->getUrl() === $url, $first);
        $this->get($url)->assertOk()->assertSee('FIRST-ORDER-LOG')->assertDontSee('SECOND-ORDER-LOG');
        Http::assertNothingSent();
        $this->assertDatabaseCount('payment_communication_logs', 2);
    }
}
