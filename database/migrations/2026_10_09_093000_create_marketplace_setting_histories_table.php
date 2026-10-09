<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketplace_setting_histories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('marketplace_setting_id')->constrained()->cascadeOnDelete();
            $table->boolean('old_enabled');
            $table->boolean('new_enabled');
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketplace_setting_histories');
    }
};
