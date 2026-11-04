<?php

namespace App\Policies;

use App\Models\Destination;
use App\Models\User;
use App\Services\AdminDestinationScopeService;

class DestinationPolicy
{
    public function updateGallery(User $user, Destination $destination): bool
    {
        return app(AdminDestinationScopeService::class)->allows($user, $destination->guid);
    }

    public function updateFacilities(User $user, Destination $destination): bool
    {
        return app(AdminDestinationScopeService::class)->allows($user, $destination->guid);
    }
}
