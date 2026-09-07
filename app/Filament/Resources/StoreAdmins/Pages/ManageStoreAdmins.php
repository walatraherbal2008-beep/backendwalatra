<?php

namespace App\Filament\Resources\StoreAdmins\Pages;

use App\Filament\Resources\StoreAdmins\StoreAdminResource;
use App\Models\User;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

class ManageStoreAdmins extends ManageRecords
{
    protected static string $resource = StoreAdminResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Tambah Akun Seller')
                ->icon('heroicon-m-plus')
                ->using(function (array $data): User {
                    return DB::transaction(function () use ($data): User {
                        $data['user_type'] = 'admin';
                        $data['email_verified_at'] = null;

                        $seller = User::query()->create($data);

                        Role::findOrCreate('store_admin', 'web');
                        $seller->syncRoles(['store_admin']);

                        return $seller;
                    });
                }),
        ];
    }
}
