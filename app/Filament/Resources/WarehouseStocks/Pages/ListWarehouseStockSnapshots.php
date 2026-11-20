<?php

namespace App\Filament\Resources\WarehouseStocks\Pages;

use App\Filament\Resources\WarehouseStocks\WarehouseStockSnapshotResource;
use App\Filament\Resources\WarehouseStocks\Widgets\WarehouseStockSyncStatus;
use App\Jobs\SyncWarehouseStockSnapshots;
use App\Models\WarehouseStockSyncRun;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Bus;
use Throwable;

class ListWarehouseStockSnapshots extends ListRecords
{
    protected static string $resource = WarehouseStockSnapshotResource::class;

    protected function authorizeAccess(): void
    {
        abort_unless(WarehouseStockSnapshotResource::canAccess(), 403);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('syncStock')
                ->label('Sinkronisasi stok')
                ->icon(Heroicon::OutlinedArrowPath)
                ->color('primary')
                ->requiresConfirmation()
                ->modalDescription('Pemeriksaan berjalan di latar belakang. Status dan progres diperbarui otomatis setiap 2 detik pada halaman ini.')
                ->authorize(fn (): bool => WarehouseStockSnapshotResource::canAccess())
                ->action(function (): void {
                    abort_unless(WarehouseStockSnapshotResource::canAccess(), 403);

                    $activeRun = WarehouseStockSyncRun::query()
                        ->whereIn('status', ['queued', 'running'])
                        ->latest('id')
                        ->first();

                    if ($activeRun) {
                        Notification::make()
                            ->title('Sinkronisasi sedang berjalan')
                            ->body('Tunggu hingga proses yang sedang berlangsung selesai.')
                            ->info()
                            ->send();

                        return;
                    }

                    if (config('queue.default') === 'sync') {
                        Notification::make()
                            ->title('Antrean latar belakang belum aktif')
                            ->body('Atur QUEUE_CONNECTION ke redis atau database dan jalankan worker antrean.')
                            ->warning()
                            ->send();

                        return;
                    }

                    $run = WarehouseStockSyncRun::query()->create([
                        'requested_by' => auth()->id(),
                        'status' => 'queued',
                    ]);

                    try {
                        Bus::dispatch(new SyncWarehouseStockSnapshots($run->id));
                    } catch (Throwable $exception) {
                        $run->update([
                            'status' => 'failed',
                            'error' => mb_substr($exception->getMessage(), 0, 2000),
                            'finished_at' => now(),
                        ]);

                        Notification::make()
                            ->title('Sinkronisasi gagal dimasukkan ke antrean')
                            ->body('Periksa koneksi antrean dan worker.')
                            ->danger()
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->title('Sinkronisasi masuk antrean')
                        ->body('Halaman ini menampilkan status terbaru secara otomatis.')
                        ->success()
                        ->send();
                }),
        ];
    }

    protected function getHeaderWidgets(): array
    {
        return [WarehouseStockSyncStatus::class];
    }

    public function getHeaderWidgetsColumns(): int|array
    {
        return 1;
    }
}
