<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketplace_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('key')->unique();
            $table->boolean('enabled')->default(false);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
        });

        DB::table('marketplace_settings')->insert([
            [
                'key' => 'self_service_store_registration_enabled',
                'enabled' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'self_service_store_admin_invitation_enabled',
                'enabled' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('marketplace_settings');
    }
};
