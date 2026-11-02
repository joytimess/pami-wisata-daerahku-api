<?php

namespace App\Policies;

use App\Models\Destination;
use App\Models\User;

class DestinationPolicy
{
    public function updateFacilities(User $user, Destination $destination): bool
    {
        // Catalog administration currently has shared scope; there is no destination owner column.
        return (int) $user->role >= 1;
    }
}
