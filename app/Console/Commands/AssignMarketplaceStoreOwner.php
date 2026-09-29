<?php

namespace App\Console\Commands;

use App\Models\Store;
use App\Models\User;
use App\Services\Marketplace\StoreOwnerAssignmentService;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

class AssignMarketplaceStoreOwner extends Command
{
    protected $signature = 'marketplace:store-assign-owner
        {store : ID toko}
        {email : Email member pemilik toko}
        {--reason= : Alasan perubahan owner}';

    protected $description = 'Menetapkan member Walatra sebagai owner toko marketplace.';

    public function handle(StoreOwnerAssignmentService $owners): int
    {
        $store = Store::query()->find($this->argument('store'));
        $owner = User::query()->where('email', $this->argument('email'))->first();

        if (! $store) {
            $this->error('Toko tidak ditemukan.');

            return self::FAILURE;
        }

        if (! $owner) {
            $this->error('Member dengan email tersebut tidak ditemukan.');

            return self::FAILURE;
        }

        try {
            $store = $owners->assign(
                $store,
                $owner,
                reason: $this->option('reason'),
            );
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $messages) {
                foreach ($messages as $message) {
                    $this->error($message);
                }
            }

            return self::FAILURE;
        }

        $this->info("Owner toko {$store->name} berhasil ditetapkan ke {$store->owner?->email}.");

        return self::SUCCESS;
    }
}
