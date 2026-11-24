<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table): void {
            $table->string('province_id', 100)->nullable();
            $table->string('city_id', 100)->nullable();
            $table->string('district_id', 100)->nullable();
            $table->string('sub_district_id', 100)->nullable();
            $table->string('sub_district_name', 100)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table): void {
            $table->dropColumn(['province_id', 'city_id', 'district_id', 'sub_district_id', 'sub_district_name']);
        });
    }
};
