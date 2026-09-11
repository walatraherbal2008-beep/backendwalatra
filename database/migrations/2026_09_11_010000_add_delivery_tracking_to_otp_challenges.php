<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('otp_challenges', function (Blueprint $table): void {
            $table->string('delivery_status', 20)->default('pending')->index();
            $table->string('provider_message_id')->nullable();
            $table->timestampTz('sent_at')->nullable();
            $table->timestampTz('failed_at')->nullable();
            $table->json('provider_response')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('otp_challenges', function (Blueprint $table): void {
            $table->dropColumn([
                'delivery_status',
                'provider_message_id',
                'sent_at',
                'failed_at',
                'provider_response',
            ]);
        });
    }
};
