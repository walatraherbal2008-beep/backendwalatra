<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('otp_challenges', function (Blueprint $table) {
            $table->id();
            $table->string('phone',20);
            $table->string('purpose',50)->default('customer_login');
            $table->string('code_hash');
            $table->timestampTz('expires_at');
            $table->unsignedBigInteger('attempts')->default(0);
            $table->unsignedBigInteger('max_attemps')->default(5);
            $table->timestampTz('verified_at')->nullable();
            $table->timestampTz('invalidated_at')->nullable();
            $table->string('request_ip',45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestampsTz();

            $table->index(['phone', 'purpose']);
            $table->index('expires_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('otp_challenges');
    }
};
