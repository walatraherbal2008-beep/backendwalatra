<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_request_audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->uuid('request_id')->unique();
            $table->string('http_method', 10);
            $table->string('endpoint', 512);
            $table->string('route_name')->nullable();
            $table->unsignedSmallInteger('status_code')->nullable();
            $table->unsignedBigInteger('duration_ms')->default(0);
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 1000)->nullable();
            $table->string('actor_type', 150)->nullable();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('authentication_status', 20)->default('guest');
            $table->string('request_content_type', 120)->nullable();
            $table->string('response_content_type', 120)->nullable();
            $table->unsignedBigInteger('request_bytes')->default(0);
            $table->unsignedBigInteger('response_bytes')->default(0);
            $table->json('query_keys')->nullable();
            $table->json('request_payload')->nullable();
            $table->json('response_payload')->nullable();
            $table->string('exception_type')->nullable();
            $table->timestampTz('started_at', 6)->index();
            $table->timestampsTz();

            $table->index(['status_code', 'started_at']);
            $table->index(['http_method', 'started_at']);
            $table->index(['endpoint', 'started_at']);
            $table->index(['actor_type', 'actor_id', 'started_at'], 'api_request_audit_actor_started_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_request_audit_logs');
    }
};
