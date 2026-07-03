<?php

namespace App\Policies;

use App\Models\Dao;
use App\Models\User;

class DaoPolicy
{
    /** Only the creator may edit; DAOs with no recorded owner are locked. */
    public function update(User $user, Dao $dao): bool
    {
        return $dao->user_id !== null && $dao->user_id === $user->id;
    }

    public function delete(User $user, Dao $dao): bool
    {
        return $this->update($user, $dao);
    }
}
