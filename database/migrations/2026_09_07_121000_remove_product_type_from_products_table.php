<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropIndex('products_status_product_type_index');
            $table->dropColumn('product_type');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->string('product_type', 30)->default('simple')->after('description');
            $table->index(['status', 'product_type']);
        });
    }
};
