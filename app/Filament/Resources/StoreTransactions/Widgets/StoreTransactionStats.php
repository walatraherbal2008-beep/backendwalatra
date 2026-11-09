<?php

namespace App\Filament\Resources\StoreTransactions\Widgets;

use App\Filament\Resources\StoreTransactions\Pages\ManageStoreTransactions;
use App\Filament\Resources\StoreTransactions\StoreTransactionResource;
use App\Services\Marketplace\StoreTransactionReport;
use Filament\Widgets\Concerns\InteractsWithPageTable;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Number;
use Livewire\Attributes\On;

class StoreTransactionStats extends StatsOverviewWidget
{
    use InteractsWithPageTable;

    protected static bool $isDiscovered = false;

    protected static bool $isLazy = false;

    protected ?string $pollingInterval = null;

    public static function canView(): bool
    {
        return StoreTransactionResource::canAccess();
    }

    protected function getTablePage(): string
    {
        return ManageStoreTransactions::class;
    }

    #[On('store-transactions-refreshed')]
    public function refreshSummary(): void {}

    protected function getStats(): array
    {
        abort_unless(self::canView(), 403);
        $storeId = $this->getTablePageInstance()->selectedStoreId();
        $summary = StoreTransactionReport::summary($this->getPageTableQuery(), $storeId);
        $label = $storeId === null ? 'Nilai order lunas: ' : 'Nilai barang toko lunas: ';

        return [
            Stat::make('Jumlah transaksi', $summary['total'])->description('Mengikuti filter dan pencarian'),
            Stat::make('Menunggu pembayaran', $summary['pending'])->color('warning'),
            Stat::make('Lunas', $summary['paid'])->color('success')
                ->description($label.Number::currency($summary['paid_amount'], in: 'IDR', locale: 'id')),
            Stat::make('Perlu pemeriksaan pembayaran', $summary['review'])->color('danger'),
        ];
    }
}
