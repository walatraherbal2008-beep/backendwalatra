<?php

namespace App\Filament\Widgets;

use App\Models\User;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class CustomerStatsWidget extends BaseWidget
{
    protected function getStats(): array
    {
        return [
            Stat::make('Total customer', User::query()->where('user_type', 'customer')->count())
                ->description('Seluruh akun customer')
                ->color('primary'),
            Stat::make('Customer aktif', User::query()->where('user_type', 'customer')->where('status', 'active')->count())
                ->description('Dapat menggunakan layanan')
                ->color('success'),
            Stat::make('Perlu perhatian', User::query()->where('user_type', 'customer')->whereIn('status', ['suspended', 'blocked', 'inactive'])->count())
                ->description('Suspended, diblokir, atau nonaktif')
                ->color('warning'),
            Stat::make('Customer baru 30 hari', User::query()->where('user_type', 'customer')->where('created_at', '>=', now()->subDays(30))->count())
                ->description('Registrasi terbaru')
                ->color('info'),
        ];
    }
}
