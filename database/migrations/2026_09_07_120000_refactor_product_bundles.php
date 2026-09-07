<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_bundles', function (Blueprint $table): void {
            $table->dropForeign(['product_id']);
            $table->dropUnique('product_bundles_product_id_unique');
            $table->dropColumn('product_id');
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('code', 100)->unique();
            $table->text('description')->nullable();
            $table->decimal('price', 15, 2)->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('product_bundles', function (Blueprint $table): void {
            $table->dropUnique('product_bundles_slug_unique');
            $table->dropUnique('product_bundles_code_unique');
            $table->dropColumn(['name', 'slug', 'code', 'description', 'price']);
            $table->foreignId('product_id')
                ->unique()
                ->constrained('products')
                ->cascadeOnDelete();
        });
    }
};
