<?php

namespace App\Policies;

use App\Models\User;

class FacilityPolicy
{
    public function viewAny(User $user): bool
    {
        return (int) $user->role >= 1;
    }

    public function manage(User $user): bool
    {
        return (int) $user->role === 2;
    }
}
