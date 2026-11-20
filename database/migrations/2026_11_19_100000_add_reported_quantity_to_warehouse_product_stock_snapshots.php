<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('warehouse_product_stock_snapshots', function (Blueprint $table): void {
            $table->integer('reported_quantity')->nullable()->after('product_id');
        });
    }

    public function down(): void
    {
        Schema::table('warehouse_product_stock_snapshots', function (Blueprint $table): void {
            $table->dropColumn('reported_quantity');
        });
    }
};
