<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warehouse_product_stock_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('warehouse_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('available_quantity')->nullable();
            $table->string('status', 20)->default('pending');
            $table->timestampTz('checked_at')->nullable();
            $table->timestampTz('last_attempted_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestampsTz();

            $table->unique(['warehouse_id', 'product_id'], 'warehouse_product_stock_snapshot_unique');
            $table->index(['status', 'last_attempted_at'], 'warehouse_product_stock_snapshot_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warehouse_product_stock_snapshots');
    }
};
