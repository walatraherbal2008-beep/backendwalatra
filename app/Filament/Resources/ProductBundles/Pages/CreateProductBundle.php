<?php

namespace App\Filament\Resources\ProductBundles\Pages;

use App\Filament\Resources\ProductBundles\ProductBundleResource;
use Filament\Resources\Pages\CreateRecord;

class CreateProductBundle extends CreateRecord
{
    protected static string $resource = ProductBundleResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
