<?php

namespace App\Filament\Admin\Resources\StoreProductListings\Pages;

use App\Filament\Admin\Resources\StoreProductListings\StoreProductListingResource;
use App\Services\Marketplace\StoreListingReactivationService;
use App\Services\Warehouse\StoreListingWarehouseAssignmentService;
use Filament\Forms\Components\Field;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class CreateStoreProductListing extends CreateRecord
{
    protected static string $resource = StoreProductListingResource::class;

    /** @var list<string> */
    private array $warehouseCodes = [];

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $this->warehouseCodes = array_values(array_filter((array) ($data['warehouse_codes'] ?? [])));
        unset($data['warehouse_codes']);
        $data['store_id'] = auth()->user()->store->getKey();
        $data['published_at'] = ($data['status'] ?? null) === 'active' ? now() : null;

        return $data;
    }

    protected function handleRecordCreation(array $data): Model
    {
        $listing = app(StoreListingReactivationService::class)
            ->createOrRestoreProductListing($data);

        // Saat create me-restore listing legasi, state gambar form masih kosong
        // (record belum ada saat mount). Sinkronkan state dengan media yang ikut
        // ter-restore sebelum saveRelationships, agar media legasi tidak
        // dianggap "abandoned" dan dihapus oleh field images.
        $this->syncImagesStateWithRecord($listing);

        return $listing;
    }

    protected function afterCreate(): void
    {
        app(StoreListingWarehouseAssignmentService::class)->sync($this->record, $this->warehouseCodes);
    }

    private function syncImagesStateWithRecord(Model $record): void
    {
        $uuids = collect($record->getMedia('images'))
            ->mapWithKeys(fn (Media $media): array => [$media->uuid => $media->uuid])
            ->all();

        if ($uuids === []) {
            return;
        }

        foreach ($this->form->getComponents() as $component) {
            if ($component instanceof Field && $component->getName() === 'images') {
                $component->state($uuids);

                break;
            }
        }
    }
}
