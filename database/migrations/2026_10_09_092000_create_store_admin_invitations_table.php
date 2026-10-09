<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_admin_invitations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invited_by')->constrained('users')->cascadeOnDelete();
            $table->foreignId('accepted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('email');
            $table->string('token_hash', 64)->unique();
            $table->timestampTz('expires_at');
            $table->timestampTz('accepted_at')->nullable();
            $table->timestampsTz();

            $table->index(['store_id', 'email']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_admin_invitations');
    }
};
