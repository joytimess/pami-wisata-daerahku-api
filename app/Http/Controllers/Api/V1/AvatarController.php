<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\AvatarPreferenceRequest;
use App\Http\Requests\AvatarUploadRequest;
use App\Http\Resources\UserResource;
use App\Services\AvatarService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

class AvatarController extends Controller
{
    public function upload(AvatarUploadRequest $request, AvatarService $avatars): UserResource
    {
        return new UserResource($avatars->upload($request->user(), $request->file('avatar'), (int) $request->validated('avatar_version')));
    }

    public function preference(AvatarPreferenceRequest $request, AvatarService $avatars): UserResource
    {
        return new UserResource($avatars->preference($request->user(), $request->validated('source'), (int) $request->validated('avatar_version')));
    }

    public function show(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user->role === 0, 403);
        $path = match ($user->avatar_source) {
            'custom' => $user->avatar_custom_path,
            'google' => $user->google_avatar_snapshot_path,
            default => null,
        };
        abort_unless($path && Storage::disk('local')->exists($path), 404);
        $response = response(Storage::disk('local')->get($path), 200, [
            'Content-Type' => str_ends_with($path, '.webp') ? 'image/webp' : 'image/png',
            'Cache-Control' => 'private, no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ]);
        $response->setEtag(hash('sha256', $user->guid.':'.$user->avatar_version));
        $response->isNotModified($request);

        return $response;
    }
}
