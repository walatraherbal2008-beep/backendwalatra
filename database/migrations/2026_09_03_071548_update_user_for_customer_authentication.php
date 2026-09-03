<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('email')->nullable()->change();
            $table->string('password')->nullable()->change();

            $table->string('phone',20)->nullable()->unique();
            $table->string('user_type',30)->default('customer');
            $table->string('status',30)->default('active');

            $table->index(['user_type','status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
                $table->dropIndex(['user_type','status']);
                $table->dropUnique(['phone']);

                $table->dropColumn([
                    'phone',
                    'user_type',
                    'status'
                ]);

                $table->string('email')->nullable(false)->change();
                $table->string('password')->nullable(false)->change();

        });
    }
};
