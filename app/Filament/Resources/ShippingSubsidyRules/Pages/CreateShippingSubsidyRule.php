<?php

namespace App\Filament\Resources\ShippingSubsidyRules\Pages;

use App\Filament\Resources\ShippingSubsidyRules\ShippingSubsidyRuleResource;
use Filament\Resources\Pages\CreateRecord;

class CreateShippingSubsidyRule extends CreateRecord
{
    protected static string $resource = ShippingSubsidyRuleResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['created_by'] = auth()->id();
        $data['updated_by'] = auth()->id();

        return $data;
    }
}
