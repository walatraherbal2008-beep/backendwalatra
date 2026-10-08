<?php

namespace App\Filament\Admin\Resources\StoreProductListings\Pages;

use App\Filament\Admin\Resources\StoreProductListings\StoreProductListingResource;
use App\Services\Warehouse\StoreListingWarehouseAssignmentService;
use Filament\Resources\Pages\EditRecord;

class EditStoreProductListing extends EditRecord
{
    protected static string $resource = StoreProductListingResource::class;

    /** @var list<string> */
    private array $warehouseCodes = [];

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->warehouseCodes = array_values(array_filter((array) ($data['warehouse_codes'] ?? [])));
        unset($data['warehouse_codes']);
        // store_id & product_id dikunci: abaikan kiriman form/browser agar
        // tidak bisa dimanipulasi menjadi toko/produk lain.
        unset($data['store_id'], $data['product_id']);
        $data['published_at'] = ($data['status'] ?? null) === 'active' ? now() : null;

        return $data;
    }

    protected function afterSave(): void
    {
        app(StoreListingWarehouseAssignmentService::class)->sync($this->record, $this->warehouseCodes);
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
