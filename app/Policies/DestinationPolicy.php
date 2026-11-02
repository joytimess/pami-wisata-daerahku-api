<?php

namespace App\Policies;

use App\Models\Destination;
use App\Models\User;

class DestinationPolicy
{
    public function updateGallery(User $user, Destination $destination): bool
    {
        return $user->status === 'active' && (int) $user->role >= 1;
    }

    public function updateFacilities(User $user, Destination $destination): bool
    {
        // Catalog administration currently has shared scope; there is no destination owner column.
        return (int) $user->role >= 1;
    }
}
