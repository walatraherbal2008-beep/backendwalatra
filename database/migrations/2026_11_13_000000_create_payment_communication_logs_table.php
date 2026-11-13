<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_communication_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('order_payment_id')->nullable()->constrained()->nullOnDelete();
            $table->string('provider')->default('midtrans');
            $table->string('environment', 20);
            $table->string('provider_order_id')->nullable()->index();
            $table->string('operation', 20)->index();
            $table->string('http_method', 10);
            $table->string('endpoint', 1024);
            $table->json('request_payload')->nullable();
            $table->json('response_payload')->nullable();
            $table->text('response_body')->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->string('outcome', 30)->default('started')->index();
            $table->string('error_type')->nullable();
            $table->string('error_message')->nullable();
            $table->timestampTz('requested_at', 6)->index();
            $table->timestampTz('finished_at', 6)->nullable();
            $table->unsignedBigInteger('duration_ms')->nullable();
            $table->timestampsTz();
            $table->index(['order_id', 'id']);
            $table->index(['order_payment_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_communication_logs');
    }
};
