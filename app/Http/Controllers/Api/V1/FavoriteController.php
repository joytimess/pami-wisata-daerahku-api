<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\FavoriteRequest;
use App\Http\Resources\FavoriteResource;
use App\Http\Resources\MessageResource;
use App\Models\Destination;
use App\Services\FavoriteService;

class FavoriteController extends Controller
{
    public function __construct(private FavoriteService $service) {}

    public function index(FavoriteRequest $request)
    {
        return FavoriteResource::collection($this->service->list($request->user(), $request->validated()));
    }

    public function store(FavoriteRequest $request, Destination $destination)
    {
        return new FavoriteResource($this->service->add($request->user(), $destination));
    }

    public function destroy(FavoriteRequest $request, Destination $destination)
    {
        $this->service->remove($request->user(), $destination);

        return new MessageResource('Favorite removed.');
    }
}
