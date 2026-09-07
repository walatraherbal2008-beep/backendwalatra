<?php

namespace App\Filament\Resources\StoreResource\Pages;

use App\Filament\Resources\StoreResource;
use Filament\Resources\Pages\CreateRecord;
use Filament\Resources\Pages\CreateRecord\Concerns\HasWizard;
use Filament\Schemas\Components\Wizard\Step;

class CreateStore extends CreateRecord
{
    use HasWizard;

    protected static string $resource = StoreResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    protected function getSteps(): array
    {
        return [
            Step::make('Seller')
                ->schema(StoreResource::identityFields())
                ->columns(2),
            Step::make('Profil Toko')
                ->schema(StoreResource::profileFields())
                ->columns(2),
            Step::make('Alamat & Lokasi')
                ->schema(StoreResource::locationFields())
                ->columns(2),
        ];
    }
}
