<?php

namespace App\Filament\Admin\Resources\StoreBundleListings\Pages;

use App\Filament\Admin\Resources\StoreBundleListings\StoreBundleListingResource;
use Filament\Resources\Pages\EditRecord;

class EditStoreBundleListing extends EditRecord
{
    protected static string $resource = StoreBundleListingResource::class;

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
