<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AdminService
{
    public function create(User $actor, array $data): User
    {
        return DB::transaction(function () use ($actor, $data) {
            $actor = User::whereKey($actor->guid)->lockForUpdate()->firstOrFail();
            if ($actor->role !== 2 || $actor->status !== 'active') {
                throw new AuthorizationException;
            }
            $user = User::create(['name' => $data['name'], 'email' => $data['email'], 'password' => $data['password']]);
            $user->role = 1;
            $user->save();

            return $user;
        }, 3);
    }

    public function list(User $actor, array $filters)
    {
        if ($actor->role !== 2 || $actor->status !== 'active') {
            throw new AuthorizationException;
        }
        $query = User::query();
        if (isset($filters['role'])) {
            $query->where('role', (int) $filters['role']);
        }
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['search'])) {
            $like = '%'.addcslashes($filters['search'], '%_\\').'%';
            $query->where(fn ($q) => $q->where('name', 'like', $like)->orWhere('email', 'like', $like));
        }

        return $query->orderByDesc('role')->orderBy('name')->orderBy('guid')->paginate($filters['per_page'] ?? 15);
    }

    public function update(User $actor, User $target, array $data): User
    {
        if (isset($data['role'])) {
            $data['role'] = (int) $data['role'];
        }

        return DB::transaction(function () use ($actor, $target, $data) {
            $superadmins = User::where('role', 2)->where('status', 'active')->orderBy('guid')->lockForUpdate()->get();
            $actor = User::whereKey($actor->guid)->lockForUpdate()->firstOrFail();
            $target = User::whereKey($target->guid)->lockForUpdate()->firstOrFail();
            if ($actor->role !== 2 || $actor->status !== 'active') {
                throw new AuthorizationException;
            }
            if ($actor->guid === $target->guid && (($data['role'] ?? $target->role) !== $target->role || ($data['status'] ?? $target->status) !== $target->status)) {
                throw ValidationException::withMessages(['role' => 'You cannot change your own role or status.']);
            }
            if ($target->role === 2 && $target->status === 'active' && (($data['role'] ?? 2) !== 2 || ($data['status'] ?? 'active') !== 'active') && $superadmins->count() <= 1) {
                throw ValidationException::withMessages(['role' => 'The last active superadmin cannot be suspended or demoted.']);
            }
            if (isset($data['role'])) {
                $target->role = $data['role'];
            }
            if (isset($data['status'])) {
                $target->status = $data['status'];
            }
            if ($target->isDirty(['role', 'status'])) {
                $target->save();
                DB::table('refresh_tokens')->where('user_guid', $target->guid)->whereNull('revoked_at')->update(['revoked_at' => now(), 'updated_at' => now()]);
                DB::table('device_tokens')->where('user_guid', $target->guid)->delete();
            }

            return $target;
        }, 3);
    }
}
