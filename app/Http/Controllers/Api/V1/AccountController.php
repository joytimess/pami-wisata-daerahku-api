<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\CloseAccountRequest;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Http\Requests\Auth\UpdateProfileRequest;
use App\Http\Resources\MessageResource;
use App\Http\Resources\UserResource;
use App\Services\AccountService;
use Illuminate\Http\Request;

class AccountController extends Controller
{
    public function __construct(private AccountService $accounts) {}

    public function update(UpdateProfileRequest $request): UserResource
    {
        return new UserResource($this->accounts->update($request->user(), $request->validated()));
    }

    public function close(CloseAccountRequest $request): MessageResource
    {
        $this->accounts->close($request->user(), $request->validated());

        return new MessageResource('Account closed.');
    }

    public function verify(string $id, string $hash): MessageResource
    {
        $this->accounts->verifyLink($id, $hash);

        return new MessageResource('Email verified.');
    }

    public function resend(Request $request): MessageResource
    {
        $this->accounts->resend($request->user());

        return new MessageResource('Verification notification sent if needed.');
    }

    public function forgot(ForgotPasswordRequest $request): MessageResource
    {
        $this->accounts->forgot($request->validated('email'));

        return new MessageResource('If the account exists, a reset link has been sent.');
    }

    public function reset(ResetPasswordRequest $request): MessageResource
    {
        $this->accounts->reset($request->validated());

        return new MessageResource('Password reset.');
    }
}
