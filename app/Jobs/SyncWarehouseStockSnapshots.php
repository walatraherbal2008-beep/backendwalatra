<?php

namespace App\Jobs;

use App\Models\WarehouseStockSyncRun;
use App\Services\Warehouse\WarehouseProductStockSnapshotSync;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class SyncWarehouseStockSnapshots implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 300;

    public int $uniqueFor = 330;

    public function __construct(public int $runId) {}

    public function uniqueId(): string
    {
        return 'warehouse-stock-full-sync';
    }

    public function handle(WarehouseProductStockSnapshotSync $sync): void
    {
        $run = WarehouseStockSyncRun::query()->find($this->runId);

        if (! $run || $run->status !== 'queued') {
            return;
        }

        $run->update([
            'status' => 'running',
            'started_at' => now(),
        ]);

        try {
            if (! config('services.warehouse_remaining_stock.enabled')) {
                throw new \RuntimeException('API sisa stok belum diaktifkan.');
            }

            $result = $sync->sync(onProgress: function (int $total, int $checked, int $succeeded, int $failed) use ($run): void {
                $run->update([
                    'total' => $total,
                    'checked' => $checked,
                    'succeeded' => $succeeded,
                    'failed' => $failed,
                ]);
            });

            $run->update([
                ...$result,
                'status' => 'completed',
                'finished_at' => now(),
            ]);
        } catch (Throwable $exception) {
            $run->update([
                'status' => 'failed',
                'error' => mb_substr($exception->getMessage(), 0, 2000),
                'finished_at' => now(),
            ]);

            throw $exception;
        }
    }

    public function failed(?Throwable $exception): void
    {
        WarehouseStockSyncRun::query()
            ->whereKey($this->runId)
            ->whereIn('status', ['queued', 'running'])
            ->update([
                'status' => 'failed',
                'error' => mb_substr($exception?->getMessage() ?? 'Job sinkronisasi gagal.', 0, 2000),
                'finished_at' => now(),
                'updated_at' => now(),
            ]);
    }
}
