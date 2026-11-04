<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class AdminDestinationScopeService
{
    public function actor(User $actor, bool $lock = false): User
    {
        $query = User::whereKey($actor->guid);
        if ($lock) {
            $query->sharedLock();
        }
        $current = $query->first();
        abort_unless($current && $current->status === 'active' && in_array((int) $current->role, [1, 2], true), 403);

        return $current;
    }

    public function allows(User $actor, string $destination): bool
    {
        $actor = $this->actor($actor);

        return (int) $actor->role === 2 || DB::table('destination_admin_assignments')->where('destination_guid', $destination)->where('user_guid', $actor->guid)->exists();
    }

    /** Acquire destination before quota, booking, actor and assignment locks. */
    public function lockDestination(string $destination): void
    {
        abort_unless(DB::table('destinations')->where('guid', $destination)->lockForUpdate()->first(['guid']), 404);
    }

    public function authorize(User $actor, string $destination, bool $lock = false, int $status = 404): User
    {
        $actor = $this->actor($actor, $lock);
        if ((int) $actor->role !== 2) {
            $query = DB::table('destination_admin_assignments')->where('destination_guid', $destination)->where('user_guid', $actor->guid);
            if ($lock) {
                $query->sharedLock();
            }
            abort_unless($query->first(), $status);
        }

        return $actor;
    }

    public function apply(mixed $query, User $actor, string $column): mixed
    {
        $actor = $this->actor($actor);
        if ((int) $actor->role !== 2) {
            $query->whereExists(fn ($assigned) => $assigned->selectRaw('1')->from('destination_admin_assignments')->whereColumn('destination_admin_assignments.destination_guid', $column)->where('destination_admin_assignments.user_guid', $actor->guid));
        }

        return $query;
    }
}
