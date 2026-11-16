<?php

namespace Tests\Feature\Seeders;

use App\Models\Product;
use App\Models\ProductBundle;
use App\Models\ProductBundleItem;
use App\Models\ProductVariant;
use Database\Seeders\CategorySeeder;
use Database\Seeders\WalatraCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WalatraCatalogSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_seeds_the_24_original_products_and_keeps_bundle_components_valid(): void
    {
        $this->seed(CategorySeeder::class);
        $this->seed(WalatraCatalogSeeder::class);

        $this->assertSame(24, Product::query()->count());
        $this->assertSame(24, ProductVariant::query()->count());
        $this->assertSame(3, ProductBundle::query()->count());
        $this->assertSame(9, ProductBundleItem::query()->count());

        $albucare = Product::query()->where('product_code', 'ALB')->firstOrFail();
        $this->assertSame('ALBUCARE', $albucare->name);
        $this->assertSame(199000, (int) $albucare->variants()->sole()->price);

        $this->assertSame(24, Product::query()->whereIn('product_code', [
            'ALB', 'AMP', 'AQU', 'CHS', 'GRC', 'HEX', 'IFI', 'KAM', 'KGE', 'KLR', 'KLRN', 'KSL',
            'LMB', 'MLS', 'OIL', 'PSM', 'QNC', 'RAD', 'SKIN', 'SKP', 'SPJ', 'SQU', 'WBW', 'WNT',
        ])->count());
    }
}
