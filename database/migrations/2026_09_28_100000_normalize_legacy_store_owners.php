<?php

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        // Store lama yang dibuat memakai akun admin diperlakukan sebagai
        // toko pusat/legacy. Akun admin tidak diubah menjadi member.
        \DB::table('stores')
            ->join('users', 'users.id', '=', 'stores.owner_user_id')
            ->where('users.user_type', 'admin')
            ->update(['stores.owner_user_id' => null]);
    }

    public function down(): void
    {
        // Owner admin tidak dapat dipulihkan secara aman tanpa mapping owner
        // yang valid. Data owner sengaja tidak direkonstruksi otomatis.
    }
};
