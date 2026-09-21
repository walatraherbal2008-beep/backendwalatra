<?php

namespace App\Filament\Admin\Resources\StoreBundleListings\Pages;

use App\Filament\Admin\Resources\StoreBundleListings\StoreBundleListingResource;
use Filament\Resources\Pages\EditRecord;

class EditStoreBundleListing extends EditRecord
{
    protected static string $resource = StoreBundleListingResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        // store_id & product_bundle_id dikunci: abaikan kiriman form/browser
        // agar tidak bisa dimanipulasi menjadi toko/bundle lain.
        unset($data['store_id'], $data['product_bundle_id']);
        $data['published_at'] = ($data['status'] ?? null) === 'active' ? now() : null;

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
