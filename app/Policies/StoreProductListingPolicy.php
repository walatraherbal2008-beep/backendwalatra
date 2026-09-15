<?php

namespace App\Policies;

use App\Models\StoreProductListing;
use App\Models\User;

class StoreProductListingPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->hasActiveStore($user);
    }

    public function view(User $user, StoreProductListing $listing): bool
    {
        return $this->ownsListing($user, $listing);
    }

    public function create(User $user): bool
    {
        return $this->hasActiveStore($user);
    }

    public function update(User $user, StoreProductListing $listing): bool
    {
        return $this->ownsListing($user, $listing);
    }

    public function delete(User $user, StoreProductListing $listing): bool
    {
        return $this->ownsListing($user, $listing);
    }

    public function restore(User $user, StoreProductListing $listing): bool
    {
        return $this->ownsListing($user, $listing);
    }

    public function forceDelete(User $user, StoreProductListing $listing): bool
    {
        return false;
    }

    private function ownsListing(User $user, StoreProductListing $listing): bool
    {
        return $this->hasActiveStore($user)
            && (int) $user->store()->value('id') === (int) $listing->store_id;
    }

    private function hasActiveStore(User $user): bool
    {
        return $user->status === 'active'
            && $user->hasRole('store_admin')
            && $user->email_verified_at !== null
            && $user->store()->where('status', 'active')->exists();
    }
}
