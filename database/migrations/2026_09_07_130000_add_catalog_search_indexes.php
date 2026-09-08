<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
        DB::statement('CREATE INDEX IF NOT EXISTS products_name_trgm_idx ON products USING gin (name gin_trgm_ops)');
        DB::statement('CREATE INDEX IF NOT EXISTS products_code_trgm_idx ON products USING gin (product_code gin_trgm_ops)');
        DB::statement('CREATE INDEX IF NOT EXISTS product_variants_sku_trgm_idx ON product_variants USING gin (sku gin_trgm_ops)');
        DB::statement('CREATE INDEX IF NOT EXISTS stores_name_trgm_idx ON stores USING gin (name gin_trgm_ops)');
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS products_name_trgm_idx');
        DB::statement('DROP INDEX IF EXISTS products_code_trgm_idx');
        DB::statement('DROP INDEX IF EXISTS product_variants_sku_trgm_idx');
        DB::statement('DROP INDEX IF EXISTS stores_name_trgm_idx');
    }
};
