<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('attempt_number');
            $table->string('provider_order_id', 100)->unique();
            $table->string('transaction_id', 100)->nullable()->unique();
            $table->string('method', 30);
            $table->string('environment', 20);
            $table->string('idempotency_key', 100);
            $table->string('state', 30)->index();
            $table->string('transaction_status', 30)->nullable();
            $table->decimal('amount', 15, 2);
            $table->string('currency', 3)->default('IDR');
            $table->json('request_payload');
            $table->json('response_payload')->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->timestampTz('paid_at')->nullable();
            $table->timestampTz('last_synced_at')->nullable();
            $table->timestampsTz();
            $table->unique(['order_id', 'attempt_number']);
            $table->unique(['order_id', 'idempotency_key']);
        });
        Schema::table('orders', function (Blueprint $table): void {
            $table->string('payment_integration', 20)->nullable();
            $table->timestampTz('payment_due_at')->nullable()->index();
            $table->foreignId('active_payment_id')->nullable()->constrained('order_payments')->nullOnDelete();
            $table->uuid('payment_operation_token')->nullable();
            $table->timestampTz('payment_operation_started_at')->nullable();
            $table->string('payment_close_reason', 20)->nullable();
            $table->timestampTz('payment_last_synced_at')->nullable()->index();
        });
        Schema::create('payment_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('idempotency_key', 100);
            $table->string('method', 30);
            $table->foreignId('payment_id')->nullable()->constrained('order_payments')->nullOnDelete();
            $table->timestampsTz();
            $table->unique(['order_id', 'idempotency_key']);
        });
        Schema::create('payment_notifications', function (Blueprint $table): void {
            $table->id();
            $table->string('provider_order_id', 100)->index();
            $table->string('environment', 20);
            $table->char('event_hash', 64)->unique();
            $table->json('payload');
            $table->timestampTz('processed_at')->nullable()->index();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_notifications');
        Schema::dropIfExists('payment_requests');
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('active_payment_id');
            $table->dropIndex(['payment_due_at']);
            $table->dropIndex(['payment_last_synced_at']);
            $table->dropColumn(['payment_integration', 'payment_due_at', 'payment_operation_token', 'payment_operation_started_at', 'payment_close_reason', 'payment_last_synced_at']);
        });
        Schema::dropIfExists('order_payments');
    }
};
