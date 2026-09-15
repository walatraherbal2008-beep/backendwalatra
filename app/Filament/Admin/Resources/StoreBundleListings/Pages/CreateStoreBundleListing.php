<?php

namespace App\Filament\Admin\Resources\StoreBundleListings\Pages;

use App\Filament\Admin\Resources\StoreBundleListings\StoreBundleListingResource;
use App\Services\Marketplace\StoreListingReactivationService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateStoreBundleListing extends CreateRecord
{
    protected static string $resource = StoreBundleListingResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['store_id'] = auth()->user()->store->getKey();
        $data['published_at'] = ($data['status'] ?? null) === 'active' ? now() : null;

        return $data;
    }

    protected function handleRecordCreation(array $data): Model
    {
        return app(StoreListingReactivationService::class)
            ->createOrRestoreBundleListing($data);
    }
}
