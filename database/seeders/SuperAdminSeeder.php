<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use InvalidArgumentException;
use RuntimeException;
use Spatie\Permission\Models\Role;

class SuperAdminSeeder extends Seeder
{
    public function run(): void
    {
        $name = trim((string) env('SUPERADMIN_NAME', 'Walatra Super Admin'));
        $email = trim((string) env('SUPERADMIN_EMAIL'));
        $password = (string) env('SUPERADMIN_PASSWORD');
        $forcePassword = filter_var(
            env('SUPERADMIN_FORCE_PASSWORD', false),
            FILTER_VALIDATE_BOOL,
        );

        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException(
                'SUPERADMIN_EMAIL wajib berupa email yang valid.',
            );
        }

        if ($password === '' || strlen($password) < 4) {
            throw new InvalidArgumentException(
                'SUPERADMIN_PASSWORD wajib diisi dan minimal 12 karakter.',
            );
        }

        $user = User::query()->where('email', $email)->first();

        if ($user && $user->user_type !== 'admin') {
            throw new RuntimeException(
                'Email SUPERADMIN_EMAIL sudah digunakan oleh user non-admin.',
            );
        }

        if (! $user) {
            User::query()->create([
                'name' => $name,
                'email' => $email,
                'password' => Hash::make($password),
                'email_verified_at' => now(),
                'user_type' => 'admin',
                'status' => 'active',
            ]);

            $user = User::query()->where('email', $email)->firstOrFail();
        }

        $attributes = [
            'name' => $name,
            'user_type' => 'admin',
            'status' => 'active',
            'email_verified_at' => $user->email_verified_at ?? now(),
        ];

        if ($user->password === null || $forcePassword) {
            $attributes['password'] = Hash::make($password);
        }

        $user->forceFill($attributes)->save();

        Role::findOrCreate('super_admin', 'web');
        Role::findOrCreate('store_admin', 'web');
        Role::findOrCreate('customer', 'web');
        $user->syncRoles(['super_admin']);

        $this->command?->info('Superadmin sudah tersedia dan disinkronkan.');
    }
}
