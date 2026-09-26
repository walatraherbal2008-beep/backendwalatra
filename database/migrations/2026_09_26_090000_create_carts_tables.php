<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('carts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->timestampsTz();
        });

        Schema::create('cart_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cart_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_product_listing_id')
                ->constrained('store_product_listings')
                ->restrictOnDelete();
            $table->foreignId('product_variant_id')
                ->constrained('product_variants')
                ->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->decimal('unit_price', 15, 2);
            $table->timestampsTz();

            $table->unique(['cart_id', 'store_product_listing_id', 'product_variant_id'], 'cart_items_cart_listing_variant_unique');
            $table->index(['cart_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cart_items');
        Schema::dropIfExists('carts');
    }
};
