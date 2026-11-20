<?php

namespace App\Filament\Resources\WarehouseStocks\Widgets;

use App\Models\WarehouseStockSyncRun;
use Filament\Widgets\Widget;

class WarehouseStockSyncStatus extends Widget
{
    protected ?string $pollingInterval = '2s';

    protected string $view = 'filament.resources.warehouse-stocks.widgets.warehouse-stock-sync-status';

    protected int|string|array $columnSpan = 'full';

    protected function getViewData(): array
    {
        $run = WarehouseStockSyncRun::query()->latest('id')->first();
        $status = match ($run?->status) {
            'queued' => 'Menunggu antrean',
            'running' => 'Sedang berjalan',
            'completed' => 'Selesai',
            'failed' => 'Gagal',
            default => 'Belum pernah dijalankan',
        };
        $percentage = $run && $run->total > 0
            ? min(100, (int) floor(($run->checked / $run->total) * 100))
            : 0;

        return [
            'run' => $run,
            'status' => $status,
            'percentage' => $percentage,
            'isActive' => in_array($run?->status, ['queued', 'running'], true),
            'timestamp' => ($run?->finished_at ?? $run?->created_at)?->timezone('Asia/Jakarta')->format('d M Y H:i'),
        ];
    }
}
