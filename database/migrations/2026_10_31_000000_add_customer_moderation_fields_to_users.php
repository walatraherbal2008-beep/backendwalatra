<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->timestampTz('status_changed_at')->nullable()->after('status');
            $table->text('status_reason')->nullable()->after('status_changed_at');
            $table->foreignId('status_changed_by')
                ->nullable()
                ->after('status_reason')
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropForeign(['status_changed_by']);
            $table->dropColumn(['status_changed_at', 'status_reason', 'status_changed_by']);
        });
    }
};
