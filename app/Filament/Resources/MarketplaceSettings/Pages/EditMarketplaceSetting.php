<?php

namespace App\Filament\Resources\MarketplaceSettings\Pages;

use App\Filament\Resources\MarketplaceSettings\MarketplaceSettingResource;
use App\Models\MarketplaceSettingHistory;
use Filament\Resources\Pages\EditRecord;

class EditMarketplaceSetting extends EditRecord
{
    protected static string $resource = MarketplaceSettingResource::class;

    private ?bool $oldEnabled = null;

    private ?bool $newEnabled = null;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        if (array_key_exists('enabled', $data) && (bool) $data['enabled'] !== (bool) $this->record->enabled) {
            $this->oldEnabled = (bool) $this->record->enabled;
            $this->newEnabled = (bool) $data['enabled'];
        }

        $data['updated_by'] = auth()->id();

        return $data;
    }

    protected function afterSave(): void
    {
        if ($this->oldEnabled === null || $this->newEnabled === null) {
            return;
        }

        MarketplaceSettingHistory::query()->create([
            'marketplace_setting_id' => $this->record->id,
            'old_enabled' => $this->oldEnabled,
            'new_enabled' => $this->newEnabled,
            'changed_by' => auth()->id(),
        ]);
    }
}
