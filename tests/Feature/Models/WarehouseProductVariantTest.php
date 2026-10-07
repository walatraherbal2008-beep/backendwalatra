<?php

namespace Tests\Feature\Models;

use App\Contracts\WarehouseDirectoryProvider;
use App\Models\ProductVariant;
use App\Services\Warehouse\WarehouseProductAssignmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class WarehouseProductVariantTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->bind(WarehouseDirectoryProvider::class, function (): object {
            return new class implements WarehouseDirectoryProvider
            {
                public function list(): array
                {
                    return [
                        ['code' => 'WH-BDG', 'initial' => 'BD', 'codename' => 'BANDUNG', 'realname' => 'Bandung', 'area_group' => 'pulaujawa'],
                        ['code' => 'WH-TASIK', 'initial' => 'TM', 'codename' => 'TASIK', 'realname' => 'Tasikmalaya', 'area_group' => 'pulaujawa'],
                    ];
                }

                public function listByGroup(string $areaGroup): array
                {
                    return array_values(array_filter(
                        $this->list(),
                        fn (array $warehouse): bool => $warehouse['area_group'] === $areaGroup,
                    ));
                }
            };
        });
    }

    public function test_variant_can_be_assigned_to_multiple_external_warehouses(): void
    {
        $variant = ProductVariant::factory()->create();
        $service = app(WarehouseProductAssignmentService::class);

        $assignments = $service->sync($variant, ['WH-BDG', 'WH-TASIK', 'WH-BDG']);

        $this->assertCount(2, $assignments);
        $this->assertDatabaseCount('warehouse_product_variants', 2);
        $this->assertEqualsCanonicalizing(
            ['WH-BDG', 'WH-TASIK'],
            $variant->warehouseAssignments()->pluck('warehouse_code')->all(),
        );
    }

    public function test_unknown_external_warehouse_code_is_rejected(): void
    {
        $variant = ProductVariant::factory()->create();

        $this->expectException(ValidationException::class);

        app(WarehouseProductAssignmentService::class)->attach($variant, 'WH-UNKNOWN');
    }

    public function test_sync_removes_old_assignments_without_touching_stock_data(): void
    {
        $variant = ProductVariant::factory()->create();
        $service = app(WarehouseProductAssignmentService::class);

        $service->sync($variant, ['WH-BDG', 'WH-TASIK']);
        $service->sync($variant, ['WH-TASIK']);

        $this->assertDatabaseHas('warehouse_product_variants', [
            'product_variant_id' => $variant->id,
            'warehouse_code' => 'WH-TASIK',
        ]);
        $this->assertDatabaseMissing('warehouse_product_variants', [
            'product_variant_id' => $variant->id,
            'warehouse_code' => 'WH-BDG',
        ]);
    }
}
