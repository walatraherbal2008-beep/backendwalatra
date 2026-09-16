<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Services\Marketplace\ProductAvailabilityService;
use Illuminate\Console\Command;

class SyncProductAvailability extends Command
{
    protected $signature = 'catalog:sync-availability';

    protected $description = 'Menyinkronkan status produk, varian, bundle, dan listing toko.';

    public function handle(ProductAvailabilityService $availability): int
    {
        $synchronized = 0;

        Product::withTrashed()->eachById(function (Product $product) use ($availability, &$synchronized): void {
            $availability->synchronizeProduct($product);
            $synchronized++;
        });

        $this->info("Sinkronisasi availability selesai untuk {$synchronized} produk.");

        return self::SUCCESS;
    }
}
