<?php

namespace App\Filament\Resources\ProductBundles\Pages;

use App\Filament\Resources\ProductBundles\ProductBundleResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditProductBundle extends EditRecord
{
    protected static string $resource = ProductBundleResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
