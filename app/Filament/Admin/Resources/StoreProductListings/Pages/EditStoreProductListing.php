<?php

namespace App\Filament\Admin\Resources\StoreProductListings\Pages;

use App\Filament\Admin\Resources\StoreProductListings\StoreProductListingResource;
use Filament\Resources\Pages\EditRecord;

class EditStoreProductListing extends EditRecord
{
    protected static string $resource = StoreProductListingResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        // store_id & product_id dikunci: abaikan kiriman form/browser agar
        // tidak bisa dimanipulasi menjadi toko/produk lain.
        unset($data['store_id'], $data['product_id']);
        $data['published_at'] = ($data['status'] ?? null) === 'active' ? now() : null;

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
