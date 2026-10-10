<?php

namespace App\Filament\Resources\ShippingSubsidyRules\Pages;

use App\Filament\Resources\ShippingSubsidyRules\ShippingSubsidyRuleResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditShippingSubsidyRule extends EditRecord
{
    protected static string $resource = ShippingSubsidyRuleResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['updated_by'] = auth()->id();

        return $data;
    }

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
