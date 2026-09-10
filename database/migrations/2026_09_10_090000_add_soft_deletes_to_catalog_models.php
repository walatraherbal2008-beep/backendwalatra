<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach ([
            'products',
            'product_variants',
            'product_bundles',
            'product_bundle_items',
            'store_product_listings',
            'store_bundle_listings',
        ] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->softDeletesTz();
            });
        }
    }

    public function down(): void
    {
        foreach ([
            'products',
            'product_variants',
            'product_bundles',
            'product_bundle_items',
            'store_product_listings',
            'store_bundle_listings',
        ] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropSoftDeletesTz();
            });
        }
    }
};
