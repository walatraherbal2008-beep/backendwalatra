<?php

namespace App\Filament\Resources\ProductBundles\Pages;

use App\Filament\Resources\ProductBundles\ProductBundleResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListProductBundles extends ListRecords
{
    protected static string $resource = ProductBundleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
