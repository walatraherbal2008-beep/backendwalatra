<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\ProductBundle;
use App\Models\Store;
use App\Models\StoreBundleListing;
use App\Models\StoreProductListing;
use App\Support\CheckoutSimulation;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PrepareOrderSimulation extends Command
{
    protected $signature = 'orders:prepare-simulation';

    protected $description = 'Menyiapkan listing toko simulasi dari katalog aktif untuk testing order lokal.';

    public function handle(): int
    {
        if (! CheckoutSimulation::enabled()) {
            $this->error('Aktifkan ORDER_SIMULATION_ENABLED pada environment local/testing terlebih dahulu.');

            return self::FAILURE;
        }

        $store = DB::transaction(function (): Store {
            $store = Store::query()->where('code', 'TOKO-SIMULASI-ORDER')->first();

            if (! $store) {
                $store = new Store;
                $store->forceFill(['code' => 'TOKO-SIMULASI-ORDER']);
                $store->fill([
                    'name' => 'Toko Simulasi Order',
                    'description' => 'Toko untuk pengujian order dengan stok, ongkir, dan pembayaran simulasi.',
                    'status' => 'active',
                    'province' => 'Jawa Barat',
                    'city' => 'Bandung',
                    'approved_at' => now(),
                ])->save();
            }

            $products = Product::query()
                ->where('status', 'active')
                ->whereHas('variants', fn ($query) => $query->where('status', 'active'))
                ->get();

            foreach ($products as $product) {
                $listing = StoreProductListing::withTrashed()->firstOrNew([
                    'store_id' => $store->id,
                    'product_id' => $product->id,
                ]);

                if ($listing->trashed()) {
                    $listing->restore();
                }

                $listing->fill(['status' => 'active', 'published_at' => $listing->published_at ?? now()])->save();
            }

            foreach (ProductBundle::query()->where('status', 'active')->with('items.variant.product')->get() as $bundle) {
                if (! $bundle->isPurchasable()) {
                    continue;
                }

                $listing = StoreBundleListing::withTrashed()->firstOrNew([
                    'store_id' => $store->id,
                    'product_bundle_id' => $bundle->id,
                ]);

                if ($listing->trashed()) {
                    $listing->restore();
                }

                $listing->fill(['status' => 'active', 'published_at' => $listing->published_at ?? now()])->save();
            }

            return $store;
        });

        $this->info('Toko simulasi siap: '.$store->name);
        $this->line('Listing produk: '.$store->listings()->count());
        $this->line('Listing bundle: '.$store->bundleListings()->count());

        return self::SUCCESS;
    }
}
