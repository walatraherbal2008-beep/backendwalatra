<?php

namespace App\Filament\Admin\Resources\StoreBundleListings\Pages;

use App\Filament\Admin\Resources\StoreBundleListings\StoreBundleListingResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListStoreBundleListings extends ListRecords
{
    protected static string $resource = StoreBundleListingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Tambah Bundle Toko'),
        ];
    }
}
