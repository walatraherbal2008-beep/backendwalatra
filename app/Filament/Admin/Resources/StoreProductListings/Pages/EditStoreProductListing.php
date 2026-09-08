<?php

namespace App\Filament\Admin\Resources\StoreProductListings\Pages;

use App\Filament\Admin\Resources\StoreProductListings\StoreProductListingResource;
use Filament\Resources\Pages\EditRecord;

class EditStoreProductListing extends EditRecord
{
    protected static string $resource = StoreProductListingResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['store_id'] = auth()->user()->store->getKey();
        $data['published_at'] = ($data['status'] ?? null) === 'active' ? now() : null;

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
