<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table): void {
            $table->string('contact_phone', 20)->nullable()->after('description');
            $table->string('contact_email')->nullable()->after('contact_phone');
            $table->string('province', 100)->nullable()->after('contact_email');
            $table->string('city', 100)->nullable()->after('province');
            $table->string('district', 100)->nullable()->after('city');
            $table->string('postal_code', 10)->nullable()->after('district');
            $table->text('address')->nullable()->after('postal_code');
            $table->decimal('latitude', 10, 7)->nullable()->after('address');
            $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
            $table->timestampTz('opened_at')->nullable()->after('approved_at');

            $table->index(['province', 'city']);
        });
    }

    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table): void {
            $table->dropIndex(['province', 'city']);
            $table->dropColumn([
                'contact_phone',
                'contact_email',
                'province',
                'city',
                'district',
                'postal_code',
                'address',
                'latitude',
                'longitude',
                'opened_at',
            ]);
        });
    }
};
