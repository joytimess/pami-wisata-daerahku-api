<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\GoogleRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RefreshRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\AuthTokenResource;
use App\Http\Resources\MessageResource;
use App\Http\Resources\UserResource;
use App\Services\AuthService;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    public function __construct(private AuthService $auth) {}

    public function register(RegisterRequest $request): AuthTokenResource
    {
        return new AuthTokenResource($this->auth->register($request->validated()));
    }

    public function login(LoginRequest $request): AuthTokenResource
    {
        return new AuthTokenResource($this->auth->login($request->validated()));
    }

    public function google(GoogleRequest $request): AuthTokenResource
    {
        return new AuthTokenResource($this->auth->google($request->validated()));
    }

    public function refresh(RefreshRequest $request): AuthTokenResource
    {
        return new AuthTokenResource($this->auth->refresh($request->validated('refresh_token')));
    }

    public function me(Request $request): UserResource
    {
        return new UserResource($request->user());
    }

    public function logout(Request $request): MessageResource
    {
        $this->auth->logout($request->user(), $request->attributes->get('api_session_guid'));

        return new MessageResource('Logged out.');
    }
}
