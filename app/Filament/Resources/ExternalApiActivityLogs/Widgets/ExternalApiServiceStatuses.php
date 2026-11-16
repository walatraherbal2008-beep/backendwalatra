<?php

namespace App\Filament\Resources\ExternalApiActivityLogs\Widgets;

use App\Filament\Resources\ExternalApiActivityLogs\ExternalApiActivityLogResource;
use App\Models\ExternalApiActivityLog;
use Filament\Widgets\Widget;

class ExternalApiServiceStatuses extends Widget
{
    protected static bool $isLazy = false;

    protected string $view = 'filament.resources.external-api-activity-logs.widgets.service-statuses';

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return ExternalApiActivityLogResource::canAccess();
    }

    protected function getViewData(): array
    {
        abort_unless(self::canView(), 403);

        $latestIds = ExternalApiActivityLog::query()->selectRaw('MAX(id)')->groupBy('service');
        $latestActivities = ExternalApiActivityLog::query()
            ->whereIn('id', $latestIds)
            ->get(['id', 'service', 'status', 'started_at'])
            ->keyBy('service');

        $services = [];

        foreach (ExternalApiActivityLogResource::serviceConfigurations() as $key => $configuration) {
            $latest = $latestActivities->get($key);

            if (! $configuration['enabled']) {
                $status = 'Nonaktif';
                $color = 'gray';
            } elseif ($latest === null) {
                $status = 'Belum ada aktivitas';
                $color = 'info';
            } elseif ($latest->status === 'running') {
                $stale = $latest->started_at?->lt(now()->subMinutes(2)) ?? false;
                $status = $stale ? 'Belum selesai' : 'Sedang berjalan';
                $color = $stale ? 'warning' : 'info';
            } elseif (in_array($latest->status, ['failed', 'timeout'], true)) {
                $status = 'Gangguan terakhir';
                $color = 'danger';
            } else {
                $status = 'Berhasil terakhir';
                $color = 'success';
            }

            $services[] = [
                'key' => $key,
                'label' => $configuration['label'],
                'status' => $status,
                'color' => $color,
                'last_activity' => $latest?->started_at?->timezone('Asia/Jakarta')->format('d M Y H:i:s'),
            ];
        }

        return ['services' => $services];
    }
}
