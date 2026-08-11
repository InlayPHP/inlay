<?php

namespace App\Policies;

use App\Models\User;

final class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->isAdministrator($user);
    }

    public function view(User $user, User $record): bool
    {
        return $this->isAdministrator($user);
    }

    public function create(User $user): bool
    {
        return $this->isAdministrator($user);
    }

    public function update(User $user, User $record): bool
    {
        return $this->isAdministrator($user);
    }

    public function delete(User $user, User $record): bool
    {
        return $this->isAdministrator($user) && ! $user->is($record);
    }

    public function deleteAny(User $user): bool
    {
        return $this->isAdministrator($user);
    }

    public function restore(User $user, User $record): bool
    {
        return $this->isAdministrator($user);
    }

    public function restoreAny(User $user): bool
    {
        return $this->isAdministrator($user);
    }

    public function forceDelete(User $user, User $record): bool
    {
        return $this->isAdministrator($user) && ! $user->is($record);
    }

    public function forceDeleteAny(User $user): bool
    {
        return $this->isAdministrator($user);
    }

    private function isAdministrator(User $user): bool
    {
        return $user->role === 'admin' && $user->active;
    }
}
