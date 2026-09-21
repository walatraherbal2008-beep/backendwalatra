<?php

namespace Tests\Feature\Models;

use App\Models\Product;
use App\Models\ProductBundle;
use App\Models\Store;
use App\Models\StoreBundleListing;
use App\Models\StoreProductListing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class StoreListingImmutabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_product_id_cannot_be_changed_after_listing_created(): void
    {
        $listing = StoreProductListing::factory()->create(['status' => 'draft']);
        $otherProduct = Product::factory()->create();

        $this->expectException(ValidationException::class);

        $listing->update(['product_id' => $otherProduct->id]);
    }

    public function test_store_id_cannot_be_changed_after_listing_created(): void
    {
        $listing = StoreProductListing::factory()->create(['status' => 'draft']);
        $otherStore = Store::factory()->create();

        $this->expectException(ValidationException::class);

        $listing->update(['store_id' => $otherStore->id]);
    }

    public function test_editable_fields_can_still_be_updated(): void
    {
        $listing = StoreProductListing::factory()->create(['status' => 'draft']);

        $listing->update([
            'description' => 'Deskripsi etalase baru yang kreatif',
            'status' => 'draft',
            'sort_order' => 5,
        ]);

        $this->assertSame('Deskripsi etalase baru yang kreatif', $listing->fresh()->description);
        $this->assertSame(5, $listing->fresh()->sort_order);
    }

    public function test_bundle_id_cannot_be_changed_after_listing_created(): void
    {
        $listing = StoreBundleListing::factory()->create(['status' => 'draft']);
        $otherBundle = ProductBundle::factory()->create();

        $this->expectException(ValidationException::class);

        $listing->update(['product_bundle_id' => $otherBundle->id]);
    }

    public function test_bundle_listing_store_id_cannot_be_changed(): void
    {
        $listing = StoreBundleListing::factory()->create(['status' => 'draft']);
        $otherStore = Store::factory()->create();

        $this->expectException(ValidationException::class);

        $listing->update(['store_id' => $otherStore->id]);
    }
}
