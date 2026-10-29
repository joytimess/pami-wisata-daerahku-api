<?php

namespace App\Http\Middleware;

use App\Services\AuthService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AuthenticateApiToken
{
    public function __construct(private AuthService $auth) {}

    public function handle(Request $request, Closure $next): mixed
    {
        [$user, $session] = $this->auth->authenticate($request->bearerToken());
        Auth::setUser($user);
        $request->setUserResolver(fn () => $user);
        $request->attributes->set('api_session_guid', $session);

        return $next($request);
    }
}
