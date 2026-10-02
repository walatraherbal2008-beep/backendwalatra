<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('addresses', function (Blueprint $table): void {
            $table->string('province_id', 100)->nullable()->after('province');
            $table->string('province_name', 100)->nullable()->after('province_id');
            $table->string('city_id', 100)->nullable()->after('city');
            $table->string('city_name', 100)->nullable()->after('city_id');
            $table->string('district_id', 100)->nullable()->after('district');
            $table->string('district_name', 100)->nullable()->after('district_id');
            $table->string('sub_district_id', 100)->nullable()->after('district_name');
            $table->string('sub_district_name', 100)->nullable()->after('sub_district_id');
            $table->text('address_note')->nullable()->after('address');
            $table->decimal('latitude', 10, 7)->nullable()->after('address_note');
            $table->decimal('longitude', 10, 7)->nullable()->after('latitude');

            $table->index(['sub_district_id', 'postal_code'], 'addresses_subdistrict_postal_index');
        });
    }

    public function down(): void
    {
        Schema::table('addresses', function (Blueprint $table): void {
            $table->dropIndex('addresses_subdistrict_postal_index');
            $table->dropColumn([
                'province_id',
                'province_name',
                'city_id',
                'city_name',
                'district_id',
                'district_name',
                'sub_district_id',
                'sub_district_name',
                'address_note',
                'latitude',
                'longitude',
            ]);
        });
    }
};
