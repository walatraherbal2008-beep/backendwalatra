<?php

namespace App\Filament\Resources\StoreTransactions\Pages;

use App\Filament\Resources\StoreTransactions\StoreTransactionResource;
use App\Filament\Resources\StoreTransactions\Widgets\StoreTransactionStats;
use Filament\Pages\Concerns\ExposesTableToWidgets;
use Filament\Resources\Pages\ManageRecords;

class ManageStoreTransactions extends ManageRecords
{
    use ExposesTableToWidgets;

    protected static string $resource = StoreTransactionResource::class;

    public function selectedStoreId(): ?int
    {
        $value = $this->getTableFilterState('store')['value'] ?? null;

        return filled($value) ? (int) $value : null;
    }

    protected function getHeaderWidgets(): array
    {
        return [StoreTransactionStats::class];
    }
}
