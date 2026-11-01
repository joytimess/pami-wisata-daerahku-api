<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\CloseAccountRequest;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\ResendEmailOtpRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Http\Requests\Auth\UpdateProfileRequest;
use App\Http\Requests\Auth\VerifyEmailOtpRequest;
use App\Http\Resources\MessageResource;
use App\Http\Resources\UserResource;
use App\Services\AccountService;
use App\Services\EmailVerificationOtpService;
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

    public function verifyOtp(VerifyEmailOtpRequest $request, EmailVerificationOtpService $otp): MessageResource
    {
        $otp->verify($request->validated('email'), $request->validated('otp'));

        return new MessageResource('Email verified.');
    }

    public function resendOtp(ResendEmailOtpRequest $request, EmailVerificationOtpService $otp): MessageResource
    {
        $otp->resend($request->validated('email'));

        return new MessageResource('If verification is needed, a code may be sent.');
    }

    public function forgot(ForgotPasswordRequest $request): MessageResource
    {
        $this->accounts->forgot($request->validated('email'));

        return new MessageResource('If the account exists, the reset request has been received.');
    }

    public function reset(ResetPasswordRequest $request): MessageResource
    {
        $this->accounts->reset($request->validated());

        return new MessageResource('Password reset.');
    }
}
