<?php

namespace App\Console\Commands;

use App\Models\Product;
use Illuminate\Console\Command;

class AuditUncategorizedProducts extends Command
{
    protected $signature = 'catalog:audit-uncategorized-products
        {--all : Sertakan produk draft dan tidak aktif}';

    protected $description = 'Mendata produk yang belum memiliki kategori.';

    public function handle(): int
    {
        $query = Product::query()
            ->whereDoesntHave('categories')
            ->orderBy('id');

        if (! $this->option('all')) {
            $query->where('status', 'active');
        }

        $products = $query->get(['id', 'name', 'product_code', 'status']);

        $this->table(
            ['ID', 'Nama produk', 'Kode produk', 'Status'],
            $products->map(fn (Product $product): array => [
                $product->id,
                $product->name,
                $product->product_code,
                $product->status,
            ])->all(),
        );
        $this->info("Total produk tanpa kategori: {$products->count()}");

        return self::SUCCESS;
    }
}
