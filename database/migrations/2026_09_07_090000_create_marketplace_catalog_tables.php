<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stores', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('owner_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('code', 50)->unique();
            $table->text('description')->nullable();
            $table->string('status', 30)->default('pending_approval');
            $table->timestampTz('email_verified_at')->nullable();
            $table->timestampTz('approved_at')->nullable();
            $table->timestampsTz();

            $table->index(['status', 'name']);
        });

        Schema::create('products', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('product_code', 100)->unique();
            $table->string('bpom_number', 100)->nullable();
            $table->text('description')->nullable();
            $table->string('product_type', 30)->default('simple');
            $table->string('status', 30)->default('draft');
            $table->timestampsTz();

            $table->index(['status', 'product_type']);
        });

        Schema::create('product_variants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')
                ->constrained('products')
                ->cascadeOnDelete();
            $table->string('name');
            $table->string('sku', 100)->unique();
            $table->decimal('price', 15, 2);
            $table->unsignedInteger('weight_grams')->default(0);
            $table->jsonb('attributes')->nullable();
            $table->string('status', 30)->default('active');
            $table->timestampsTz();

            $table->index(['product_id', 'status']);
        });

        Schema::create('product_bundles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')
                ->unique()
                ->constrained('products')
                ->cascadeOnDelete();
            $table->string('status', 30)->default('active');
            $table->timestampsTz();
        });

        Schema::create('product_bundle_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_bundle_id')
                ->constrained('product_bundles')
                ->cascadeOnDelete();
            $table->foreignId('product_variant_id')
                ->constrained('product_variants')
                ->restrictOnDelete();
            $table->unsignedInteger('quantity')->default(1);
            $table->timestampsTz();

            $table->unique(['product_bundle_id', 'product_variant_id']);
        });

        Schema::create('store_product_listings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('store_id')
                ->constrained('stores')
                ->cascadeOnDelete();
            $table->foreignId('product_id')
                ->constrained('products')
                ->cascadeOnDelete();
            $table->string('status', 30)->default('draft');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestampTz('published_at')->nullable();
            $table->timestampsTz();

            $table->unique(['store_id', 'product_id']);
            $table->index(['store_id', 'status', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_product_listings');
        Schema::dropIfExists('product_bundle_items');
        Schema::dropIfExists('product_bundles');
        Schema::dropIfExists('product_variants');
        Schema::dropIfExists('products');
        Schema::dropIfExists('stores');
    }
};
