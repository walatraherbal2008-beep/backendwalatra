<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_product_listing_warehouses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('store_product_listing_id')
                ->constrained('store_product_listings')
                ->cascadeOnDelete();
            $table->string('warehouse_code', 100);
            $table->timestampsTz();

            $table->unique(['store_product_listing_id', 'warehouse_code']);
            $table->index('warehouse_code');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_product_listing_warehouses');
    }
};
