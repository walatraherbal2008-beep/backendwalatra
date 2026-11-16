<?php

namespace App\Filament\Resources\ExternalApiActivityLogs\Widgets;

use App\Filament\Resources\ExternalApiActivityLogs\ExternalApiActivityLogResource;
use App\Models\ExternalApiActivityLog;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class ExternalApiActivityStats extends StatsOverviewWidget
{
    protected static bool $isLazy = false;

    protected ?string $pollingInterval = '5s';

    public static function canView(): bool
    {
        return ExternalApiActivityLogResource::canAccess();
    }

    protected function getStats(): array
    {
        abort_unless(self::canView(), 403);

        $window = ExternalApiActivityLog::query()
            ->where('started_at', '>=', now()->subMinutes(5))
            ->selectRaw('COUNT(*) as total')
            ->selectRaw("SUM(CASE WHEN status = 'succeeded' THEN 1 ELSE 0 END) as succeeded")
            ->selectRaw("SUM(CASE WHEN status IN ('failed', 'timeout') THEN 1 ELSE 0 END) as failed")
            ->selectRaw('AVG(duration_ms) as average_duration')
            ->first();

        $configured = collect(ExternalApiActivityLogResource::serviceConfigurations())
            ->where('enabled', true)->count();
        $total = (int) ($window->total ?? 0);
        $finished = (int) ($window->succeeded ?? 0) + (int) ($window->failed ?? 0);
        $successRate = $finished > 0 ? number_format(((int) $window->succeeded / $finished) * 100, 1).'%' : '—';

        return [
            Stat::make('API aktif', $configured.' / 6')->description('Integrasi dengan konfigurasi lengkap')->color('primary'),
            Stat::make('Request 5 menit', $total)->description('Termasuk request yang sedang berjalan')->color('info'),
            Stat::make('Tingkat sukses', $successRate)->description('Dari request yang sudah selesai')->color($finished > 0 && (int) $window->failed > 0 ? 'warning' : 'success'),
            Stat::make('Rata-rata respons', $total > 0 && $window->average_duration !== null ? number_format((float) $window->average_duration).' ms' : '—')->description('Request selesai dalam 5 menit terakhir'),
        ];
    }
}
