<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warehouses', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 100)->unique();
            $table->string('realname');
            $table->string('codename');
            $table->string('initial');
            $table->string('area_group')->index();
            $table->text('fulladdr')->nullable();
            $table->string('postal_code')->nullable();
            $table->string('coordinates')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 11, 7)->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_listed')->default(true);
            $table->timestampTz('synced_at');
            $table->timestampsTz();
        });
        Schema::table('orders', function (Blueprint $table): void {
            $table->json('stock_check')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('orders', fn (Blueprint $table) => $table->dropColumn('stock_check'));
        Schema::dropIfExists('warehouses');
    }
};
