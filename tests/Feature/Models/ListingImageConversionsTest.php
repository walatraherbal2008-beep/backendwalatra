<?php

namespace Tests\Feature\Models;

use App\Models\StoreBundleListing;
use App\Models\StoreProductListing;
use App\Support\ListingImageConversions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\Conversions\Jobs\PerformConversionsJob;
use Tests\TestCase;

class ListingImageConversionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_listing_models_register_thumb_preview_full_conversions(): void
    {
        $product = new StoreProductListing;
        $product->registerAllMediaConversions();
        $bundle = new StoreBundleListing;
        $bundle->registerAllMediaConversions();

        $productConversions = collect($product->mediaConversions)->map->getName()->all();
        $bundleConversions = collect($bundle->mediaConversions)->map->getName()->all();

        foreach (['thumb', 'preview', 'full'] as $name) {
            $this->assertContains($name, $productConversions);
            $this->assertContains($name, $bundleConversions);
        }

        $this->assertSame(300, ListingImageConversions::THUMB_SIZE);
        $this->assertSame(800, ListingImageConversions::PREVIEW_SIZE);
        $this->assertSame(1600, ListingImageConversions::FULL_SIZE);
    }

    public function test_thumb_is_sync_while_preview_and_full_are_queued(): void
    {
        $model = new StoreProductListing;
        $model->registerAllMediaConversions();
        $conversions = collect($model->mediaConversions)->keyBy->getName();
        $thumb = $conversions['thumb'];
        $preview = $conversions['preview'];
        $full = $conversions['full'];

        $this->assertFalse($thumb->shouldBeQueued());
        $this->assertTrue($preview->shouldBeQueued());
        $this->assertTrue($full->shouldBeQueued());
    }

    public function test_upload_generates_thumb_sync_and_queues_the_rest(): void
    {
        Storage::fake('public');
        Queue::fake();

        $listing = StoreProductListing::factory()->create(['status' => 'draft']);
        $file = UploadedFile::fake()->image('etalase.jpg', 2000, 1500);

        $media = $listing->addMedia($file)->toMediaCollection('images');

        // Thumb sync: file turunan langsung ada.
        $this->assertTrue($media->hasGeneratedConversion('thumb'));

        // Preview + full masuk queue redis (difake di test).
        Queue::assertPushed(PerformConversionsJob::class);

        $urls = ListingImageConversions::urls($media);

        $this->assertNotNull($urls['url']);
        $this->assertNotNull($urls['thumb']);
        // Belum diproses queue di test, jadi null — FE harus fallback ke url.
        $this->assertNull($urls['preview']);
        $this->assertNull($urls['full']);
    }

    public function test_urls_returns_nulls_when_no_media(): void
    {
        $this->assertSame(
            ['url' => null, 'thumb' => null, 'preview' => null, 'full' => null],
            ListingImageConversions::urls(null),
        );
    }
}
