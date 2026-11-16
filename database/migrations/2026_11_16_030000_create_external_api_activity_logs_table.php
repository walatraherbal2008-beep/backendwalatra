<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('external_api_activity_logs', function (Blueprint $table): void {
            $table->id();
            $table->string('service', 50);
            $table->string('operation', 120);
            $table->string('http_method', 10);
            $table->string('endpoint', 1024);
            $table->uuid('correlation_id')->index();
            $table->string('status', 20)->default('running')->index();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->unsignedBigInteger('duration_ms')->nullable();
            $table->json('request_payload')->nullable();
            $table->json('response_payload')->nullable();
            $table->string('error_type')->nullable();
            $table->text('error_message')->nullable();
            $table->timestampTz('started_at', 6)->index();
            $table->timestampTz('finished_at', 6)->nullable();
            $table->timestampsTz();
            $table->index(['service', 'started_at']);
            $table->index(['service', 'id']);
            $table->index(['status', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('external_api_activity_logs');
    }
};
