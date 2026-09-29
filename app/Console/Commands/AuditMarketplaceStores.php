<?php

namespace App\Console\Commands;

use App\Models\Store;
use App\Services\Marketplace\StoreOwnerAssignmentService;
use Illuminate\Console\Command;

class AuditMarketplaceStores extends Command
{
    protected $signature = 'marketplace:stores-audit
        {--mark-invalid : Ubah toko tanpa owner valid menjadi pending_owner}';

    protected $description = 'Audit owner dan kelayakan toko marketplace.';

    public function handle(StoreOwnerAssignmentService $owners): int
    {
        $stores = Store::query()->with('owner.roles')->orderBy('id')->get();
        $rows = [];

        foreach ($stores as $store) {
            $valid = $owners->isValidOwner($store->owner);
            $legacy = $store->owner_user_id === null;

            if ($this->option('mark-invalid') && ! $valid && $store->status === 'active') {
                $store->update(['status' => 'pending_owner']);
            }

            $rows[] = [
                $store->id,
                $store->name,
                $store->status,
                $store->owner?->email ?? '-',
                $legacy ? 'legacy' : ($valid ? 'valid' : 'invalid'),
            ];
        }

        $this->table(['ID', 'Toko', 'Status', 'Owner', 'Kelayakan'], $rows);

        return self::SUCCESS;
    }
}
