<?php

namespace App\Policies;

use App\Models\Store;
use App\Models\User;

class StorePolicy
{
    public function before(User $user): ?bool
    {
        return $user->hasRole('super_admin') ? true : false;
    }

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Store $store): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Store $store): bool
    {
        return true;
    }

    public function delete(User $user, Store $store): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
