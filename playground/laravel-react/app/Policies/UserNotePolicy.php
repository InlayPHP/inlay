<?php

namespace App\Policies;

use App\Models\User;
use App\Models\UserNote;

final class UserNotePolicy
{
    public function viewAny(User $user): bool
    {
        return $this->isAdministrator($user);
    }

    public function view(User $user, UserNote $record): bool
    {
        return $this->isAdministrator($user);
    }

    public function create(User $user): bool
    {
        return $this->isAdministrator($user);
    }

    public function update(User $user, UserNote $record): bool
    {
        return $this->isAdministrator($user);
    }

    public function delete(User $user, UserNote $record): bool
    {
        return $this->isAdministrator($user);
    }

    private function isAdministrator(User $user): bool
    {
        return $user->role === 'admin' && $user->active;
    }
}
