<?php

namespace Tests\Feature\Filament;

use App\Filament\Admin\Resources\StoreBundleListings\Pages\CreateStoreBundleListing;
use App\Filament\Admin\Resources\StoreProductListings\Pages\CreateStoreProductListing;
use App\Filament\Admin\Resources\StoreProductListings\Pages\EditStoreProductListing;
use App\Models\Product;
use App\Models\ProductBundle;
use App\Models\ProductBundleItem;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Models\StoreProductListing;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class StoreListingImageRequirementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Halaman Filament diuji di luar request HTTP; panel current harus
        // di-set ke panel admin agar route/resource terdaftar dikenali.
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_create_product_listing_active_without_image_is_rejected(): void
    {
        $admin = $this->storeAdminWithStore();
        $product = $this->activeProduct();
        $this->actingAs($admin);

        Livewire::test(CreateStoreProductListing::class)
            ->fillForm([
                'product_id' => $product->id,
                'status' => 'active',
            ])
            ->call('create')
            ->assertHasFormErrors(['images' => 'required']);

        $this->assertDatabaseCount('store_product_listings', 0);
    }

    public function test_create_product_listing_active_with_image_succeeds(): void
    {
        Storage::fake('public');
        $admin = $this->storeAdminWithStore();
        $product = $this->activeProduct();
        $this->actingAs($admin);

        Livewire::test(CreateStoreProductListing::class)
            ->fillForm([
                'product_id' => $product->id,
                'status' => 'active',
                'images' => [UploadedFile::fake()->image('etalase.jpg', 900, 700)],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $listing = StoreProductListing::query()->sole();

        $this->assertSame('active', $listing->status);
        $this->assertCount(1, $listing->getMedia('images'));
    }

    public function test_create_bundle_listing_active_without_image_is_rejected(): void
    {
        $admin = $this->storeAdminWithStore();
        $bundle = $this->activeBundle();
        $this->actingAs($admin);

        Livewire::test(CreateStoreBundleListing::class)
            ->fillForm([
                'product_bundle_id' => $bundle->id,
                'status' => 'active',
            ])
            ->call('create')
            ->assertHasFormErrors(['images' => 'required']);

        $this->assertDatabaseCount('store_bundle_listings', 0);
    }

    public function test_activate_draft_product_listing_without_image_is_rejected(): void
    {
        $admin = $this->storeAdminWithStore();
        $listing = StoreProductListing::factory()->create([
            'store_id' => $admin->store->id,
            'status' => 'draft',
        ]);
        $this->actingAs($admin);

        Livewire::test(EditStoreProductListing::class, ['record' => $listing->getKey()])
            ->fillForm(['status' => 'active'])
            ->call('save')
            ->assertHasFormErrors(['images' => 'required']);

        $this->assertSame('draft', $listing->fresh()->status);
    }

    public function test_activate_draft_product_listing_with_existing_image_succeeds(): void
    {
        Storage::fake('public');
        $admin = $this->storeAdminWithStore();
        $listing = StoreProductListing::factory()->create([
            'store_id' => $admin->store->id,
            'product_id' => $this->activeProduct()->id,
            'status' => 'draft',
        ]);
        $listing->addMedia(UploadedFile::fake()->image('sudah-ada.jpg'))->toMediaCollection('images');
        $this->actingAs($admin);

        Livewire::test(EditStoreProductListing::class, ['record' => $listing->getKey()])
            ->fillForm(['status' => 'active'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('active', $listing->fresh()->status);
    }

    public function test_legacy_active_listing_without_image_remains_editable(): void
    {
        $admin = $this->storeAdminWithStore();
        $listing = StoreProductListing::factory()->create([
            'store_id' => $admin->store->id,
            'product_id' => $this->activeProduct()->id,
            'status' => 'active',
        ]);
        $this->assertEmpty($listing->getMedia('images'));
        $this->actingAs($admin);

        Livewire::test(EditStoreProductListing::class, ['record' => $listing->getKey()])
            ->fillForm(['description' => 'Deskripsi hasil edit legasi.'])
            ->call('save')
            ->assertHasNoFormErrors();

        $listing->refresh();
        $this->assertSame('active', $listing->status);
        $this->assertStringContainsString('Deskripsi hasil edit legasi.', $listing->description);
    }

    public function test_removing_last_image_from_active_listing_is_rejected(): void
    {
        Storage::fake('public');
        $admin = $this->storeAdminWithStore();
        $listing = StoreProductListing::factory()->create([
            'store_id' => $admin->store->id,
            'product_id' => $this->activeProduct()->id,
            'status' => 'active',
        ]);
        $listing->addMedia(UploadedFile::fake()->image('satunya-satu.jpg'))->toMediaCollection('images');
        $this->actingAs($admin);

        Livewire::test(EditStoreProductListing::class, ['record' => $listing->getKey()])
            ->fillForm(['images' => []])
            ->call('save')
            ->assertHasFormErrors(['images' => 'required']);

        $this->assertCount(1, $listing->fresh()->getMedia('images'));
    }

    public function test_create_active_restore_candidate_does_not_require_reupload(): void
    {
        Storage::fake('public');
        $admin = $this->storeAdminWithStore();
        $product = $this->activeProduct();
        $deleted = StoreProductListing::factory()->create([
            'store_id' => $admin->store->id,
            'product_id' => $product->id,
            'status' => 'active',
        ]);
        $deleted->addMedia(UploadedFile::fake()->image('gambar-legasi.jpg'))->toMediaCollection('images');
        $deleted->delete();
        $this->actingAs($admin);

        Livewire::test(CreateStoreProductListing::class)
            ->fillForm([
                'product_id' => $product->id,
                'status' => 'active',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $listing = StoreProductListing::query()->sole();

        $this->assertFalse($listing->trashed());
        $this->assertSame('active', $listing->status);
        $this->assertCount(1, $listing->getMedia('images'));
    }

    public function test_create_draft_listing_does_not_require_image(): void
    {
        $admin = $this->storeAdminWithStore();
        $product = $this->activeProduct();
        $this->actingAs($admin);

        Livewire::test(CreateStoreProductListing::class)
            ->fillForm([
                'product_id' => $product->id,
                'status' => 'draft',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame('draft', StoreProductListing::query()->sole()->status);
    }

    private function storeAdminWithStore(): User
    {
        Role::findOrCreate('store_admin', 'web');
        $admin = User::factory()->create([
            'user_type' => 'customer',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
        $admin->assignRole('store_admin');
        Store::factory()->create(['owner_user_id' => $admin->id, 'status' => 'active']);

        return $admin->load('store');
    }

    private function activeProduct(): Product
    {
        $product = Product::factory()->create(['status' => 'active']);
        ProductVariant::factory()->create(['product_id' => $product->id, 'status' => 'active']);

        return $product;
    }

    private function activeBundle(): ProductBundle
    {
        $variant = ProductVariant::factory()->create(['status' => 'active']);
        $bundle = ProductBundle::factory()->create(['status' => 'active']);
        ProductBundleItem::factory()->create([
            'product_bundle_id' => $bundle->id,
            'product_variant_id' => $variant->id,
        ]);

        return $bundle;
    }
}
