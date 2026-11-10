<?php

namespace App\Filament\Resources\Orders\Pages;

use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\Orders\Widgets\OrderStats;
use App\Models\Order;
use App\Services\Marketplace\StoreTransactionReport;
use Filament\Actions\Action;
use Filament\Pages\Concerns\ExposesTableToWidgets;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Icons\Heroicon;

class ManageOrders extends ManageRecords
{
    use ExposesTableToWidgets;

    protected static string $resource = OrderResource::class;

    protected string $view = 'filament.resources.orders.pages.manage-orders';

    protected ?string $subheading = 'Seluruh sumber pesanan. Diperbarui otomatis setiap 5 detik.';

    public function selectedStoreId(): ?int
    {
        $value = $this->getTableFilterState('store')['value'] ?? null;

        return filled($value) ? (int) $value : null;
    }

    public function refreshMonitoring(): void
    {
        abort_unless(OrderResource::canAccess(), 403);
        $this->flushCachedTableRecords();
        foreach (array_keys($this->getMountedActions()) as $index) {
            $record = $this->getSchema('mountedActionSchema'.$index)?->getRecord();
            if ($record instanceof Order) {
                $record->refresh();
                StoreTransactionReport::loadDetails($record);
            }
        }
        $this->dispatch('orders-monitoring-refreshed');
    }

    protected function getHeaderActions(): array
    {
        return [Action::make('refreshMonitoring')->label('Muat ulang')->icon(Heroicon::OutlinedArrowPath)
            ->action(fn () => $this->refreshMonitoring())];
    }

    protected function getHeaderWidgets(): array
    {
        return [OrderStats::class];
    }
}
