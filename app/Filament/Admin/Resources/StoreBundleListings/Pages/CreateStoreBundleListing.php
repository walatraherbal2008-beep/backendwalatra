<?php

namespace App\Filament\Admin\Resources\StoreBundleListings\Pages;

use App\Filament\Admin\Resources\StoreBundleListings\StoreBundleListingResource;
use App\Services\Marketplace\StoreListingReactivationService;
use Filament\Forms\Components\Field;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class CreateStoreBundleListing extends CreateRecord
{
    protected static string $resource = StoreBundleListingResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['store_id'] = auth()->user()->activeManagedStore()->getKey();
        $data['published_at'] = ($data['status'] ?? null) === 'active' ? now() : null;

        return $data;
    }

    protected function handleRecordCreation(array $data): Model
    {
        $listing = app(StoreListingReactivationService::class)
            ->createOrRestoreBundleListing($data);

        // Saat create me-restore listing legasi, state gambar form masih kosong
        // (record belum ada saat mount). Sinkronkan state dengan media yang ikut
        // ter-restore sebelum saveRelationships, agar media legasi tidak
        // dianggap "abandoned" dan dihapus oleh field images.
        $this->syncImagesStateWithRecord($listing);

        return $listing;
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
