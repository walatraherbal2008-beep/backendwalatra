<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('warehouse_stock_sync_runs', function (Blueprint $table): void {
            $table->unsignedInteger('total')->default(0)->after('status');
        });

        DB::table('warehouse_stock_sync_runs')
            ->whereNotIn('status', ['queued', 'running'])
            ->update(['total' => DB::raw('checked')]);
    }

    public function down(): void
    {
        Schema::table('warehouse_stock_sync_runs', function (Blueprint $table): void {
            $table->dropColumn('total');
        });
    }
};
