<?php

namespace App\Filament\Resources\ShippingSubsidyRules\Pages;

use App\Filament\Resources\ShippingSubsidyRules\ShippingSubsidyRuleResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListShippingSubsidyRules extends ListRecords
{
    protected static string $resource = ShippingSubsidyRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
