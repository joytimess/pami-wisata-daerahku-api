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
