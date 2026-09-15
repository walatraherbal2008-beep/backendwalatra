<?php

namespace App\Filament\Admin\Resources\StoreProductListings\Pages;

use App\Filament\Admin\Resources\StoreProductListings\StoreProductListingResource;
use App\Services\Marketplace\StoreListingReactivationService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateStoreProductListing extends CreateRecord
{
    protected static string $resource = StoreProductListingResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['store_id'] = auth()->user()->store->getKey();
        $data['published_at'] = ($data['status'] ?? null) === 'active' ? now() : null;

        return $data;
    }

    protected function handleRecordCreation(array $data): Model
    {
        return app(StoreListingReactivationService::class)
            ->createOrRestoreProductListing($data);
    }
}
