<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\CatalogRequest;
use App\Http\Requests\DestinationImageRequest;
use App\Http\Resources\DestinationResource;
use App\Models\Destination;
use App\Services\CatalogService;

class DestinationController extends Controller
{
    public function __construct(private CatalogService $service) {}

    public function index(CatalogRequest $request)
    {
        return DestinationResource::collection($this->service->destinations($request->validated()));
    }

    public function show(CatalogRequest $request, Destination $destination)
    {
        return new DestinationResource($this->service->destination($destination));
    }

    public function store(CatalogRequest $request)
    {
        return new DestinationResource($this->service->destinationSave($request->validated()));
    }

    public function update(CatalogRequest $request, Destination $destination)
    {
        return new DestinationResource($this->service->destinationSave($request->validated(), $destination));
    }

    public function destroy(CatalogRequest $request, Destination $destination)
    {
        return new DestinationResource($this->service->deactivate($destination));
    }

    public function adminIndex(CatalogRequest $request)
    {
        return DestinationResource::collection($this->service->adminDestinations($request->validated()));
    }

    public function adminShow(CatalogRequest $request, Destination $destination)
    {
        return new DestinationResource($destination->load('category')->loadCount('reviews as rating_count')->loadAvg('reviews as rating_average', 'rating'));
    }

    public function image(CatalogRequest $request, Destination $destination)
    {
        return $this->service->image($destination, $request->routeIs('destinations.image.admin'));
    }

    public function updateImage(DestinationImageRequest $request, Destination $destination)
    {
        return new DestinationResource($this->service->destinationSave(['image' => $request->file('image')], $destination));
    }
}
