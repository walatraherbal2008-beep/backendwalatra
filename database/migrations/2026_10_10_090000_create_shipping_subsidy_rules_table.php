<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipping_subsidy_rules', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('scope_type', 30)->default('all');
            $table->string('scope_code', 100)->nullable();
            $table->string('scope_name')->nullable();
            $table->decimal('min_subtotal', 15, 2)->default(0);
            $table->decimal('max_subsidy', 15, 2)->default(0);
            $table->unsignedInteger('max_weight_grams')->nullable();
            $table->unsignedInteger('priority')->default(0);
            $table->boolean('enabled')->default(false);
            $table->timestampTz('starts_at')->nullable();
            $table->timestampTz('ends_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->index(['enabled', 'scope_type', 'scope_code']);
            $table->index(['starts_at', 'ends_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipping_subsidy_rules');
    }
};
