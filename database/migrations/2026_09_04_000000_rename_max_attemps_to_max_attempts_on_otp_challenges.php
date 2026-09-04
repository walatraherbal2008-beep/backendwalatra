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
        Schema::table('otp_challenges', function (Blueprint $table): void {
            $table->renameColumn('max_attemps', 'max_attempts');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('otp_challenges', function (Blueprint $table): void {
            $table->renameColumn('max_attempts', 'max_attemps');
        });
    }
};
