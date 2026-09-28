<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cart_items', function (Blueprint $table): void {
            $table->string('item_type', 20)->default('product')->after('cart_id');
            $table->foreignId('store_bundle_listing_id')
                ->nullable()
                ->after('store_product_listing_id')
                ->constrained('store_bundle_listings')
                ->restrictOnDelete();
            $table->foreignId('store_product_listing_id')->nullable()->change();
            $table->foreignId('product_variant_id')->nullable()->change();
            $table->dropUnique('cart_items_cart_listing_variant_unique');
            $table->unique(
                ['cart_id', 'store_product_listing_id', 'product_variant_id', 'store_bundle_listing_id'],
                'cart_items_identity_unique',
            );
        });

        Schema::table('order_items', function (Blueprint $table): void {
            $table->string('item_type', 20)->default('product')->after('order_id');
            $table->foreignId('store_bundle_listing_id')
                ->nullable()
                ->after('store_product_listing_id')
                ->constrained('store_bundle_listings')
                ->restrictOnDelete();
            $table->foreignId('store_product_listing_id')->nullable()->change();
            $table->foreignId('product_variant_id')->nullable()->change();
            $table->json('bundle_components')->nullable()->after('store_name');
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table): void {
            $table->dropForeign(['store_bundle_listing_id']);
            $table->dropColumn(['item_type', 'store_bundle_listing_id', 'bundle_components']);
            $table->foreignId('store_product_listing_id')->nullable(false)->change();
            $table->foreignId('product_variant_id')->nullable(false)->change();
        });

        Schema::table('cart_items', function (Blueprint $table): void {
            $table->dropUnique('cart_items_identity_unique');
            $table->dropForeign(['store_bundle_listing_id']);
            $table->dropColumn(['item_type', 'store_bundle_listing_id']);
            $table->foreignId('store_product_listing_id')->nullable(false)->change();
            $table->foreignId('product_variant_id')->nullable(false)->change();
            $table->unique(
                ['cart_id', 'store_product_listing_id', 'product_variant_id'],
                'cart_items_cart_listing_variant_unique',
            );
        });
    }
};
