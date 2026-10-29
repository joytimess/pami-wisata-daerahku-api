<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\CreateAdminRequest;
use App\Http\Requests\Auth\UpdateAdminRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\AdminService;

class AdminAccountController extends Controller
{
    public function __construct(private AdminService $admins) {}

    public function store(CreateAdminRequest $request): UserResource
    {
        return new UserResource($this->admins->create($request->user(), $request->validated()));
    }

    public function update(UpdateAdminRequest $request, User $user): UserResource
    {
        return new UserResource($this->admins->update($request->user(), $user, $request->validated()));
    }
}
