<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cart_items', function (Blueprint $table): void {
            $table->dropUnique('cart_items_identity_unique');
            $table->unique(
                ['cart_id', 'store_product_listing_id', 'product_variant_id'],
                'cart_items_product_identity_unique',
            );
            $table->unique(
                ['cart_id', 'store_bundle_listing_id'],
                'cart_items_bundle_identity_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::table('cart_items', function (Blueprint $table): void {
            $table->dropUnique('cart_items_product_identity_unique');
            $table->dropUnique('cart_items_bundle_identity_unique');
            $table->unique(
                ['cart_id', 'store_product_listing_id', 'product_variant_id', 'store_bundle_listing_id'],
                'cart_items_identity_unique',
            );
        });
    }
};
