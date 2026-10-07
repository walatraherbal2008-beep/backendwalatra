<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warehouse_product_variants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_variant_id')
                ->constrained('product_variants')
                ->cascadeOnDelete();
            $table->string('warehouse_code', 100);
            $table->timestampsTz();

            $table->unique(['product_variant_id', 'warehouse_code']);
            $table->index('warehouse_code');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warehouse_product_variants');
    }
};
