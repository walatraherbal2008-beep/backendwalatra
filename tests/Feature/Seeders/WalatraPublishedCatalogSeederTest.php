<?php

namespace Tests\Feature\Seeders;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Models\StoreProductListing;
use App\Models\User;
use Database\Seeders\WalatraPublishedCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class WalatraPublishedCatalogSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_seeds_a_store_owner_and_published_original_catalog_without_duplicates(): void
    {
        $this->seed(WalatraPublishedCatalogSeeder::class);
        $this->seed(WalatraPublishedCatalogSeeder::class);

        $owner = User::query()->where('email', 'toko1@mail.com')->firstOrFail();
        $store = Store::query()->where('slug', 'toko-herbal-abadi')->firstOrFail();

        $this->assertTrue(Hash::check('admintoko', $owner->password));
        $this->assertTrue($owner->hasRole('store_admin'));
        $this->assertSame($owner->id, $store->owner_user_id);
        $this->assertSame('active', $store->status);
        $this->assertSame(24, Product::query()->count());
        $this->assertSame(24, ProductVariant::query()->count());
        $this->assertSame(24, StoreProductListing::query()->count());
        $this->assertSame(24, StoreProductListing::query()
            ->where('store_id', $store->id)
            ->where('status', 'active')
            ->whereNotNull('published_at')
            ->count());

        $albucare = Product::query()->where('product_code', 'ALB')->firstOrFail();
        $this->assertSame('ALBUCARE', $albucare->name);
        $this->assertSame(199000, (int) $albucare->variants()->sole()->price);
        $this->assertSame(24, Product::query()->whereIn('product_code', [
            'ALB', 'AMP', 'AQU', 'CHS', 'GRC', 'HEX', 'IFI', 'KAM', 'KGE', 'KLR', 'KLRN', 'KSL',
            'LMB', 'MLS', 'OIL', 'PSM', 'QNC', 'RAD', 'SKIN', 'SKP', 'SPJ', 'SQU', 'WBW', 'WNT',
        ])->count());

        $this->getJson('/api/v1/catalog/products?per_page=50')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(24, 'data')
            ->assertJsonPath('data.0.store.name', 'Toko Herbal Abadi');
    }
}
