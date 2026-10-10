<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->decimal('shipping_gross_fee', 15, 2)->default(0)->after('subtotal');
            $table->decimal('shipping_subsidy', 15, 2)->default(0)->after('shipping_gross_fee');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn(['shipping_gross_fee', 'shipping_subsidy']);
        });
    }
};
