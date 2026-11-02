<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\CatalogRequest;
use App\Http\Requests\DestinationGalleryRequest;
use App\Http\Resources\DestinationResource;
use App\Models\Destination;
use App\Models\DestinationImage;
use App\Services\CatalogService;
use App\Services\DestinationGalleryService;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

class DestinationController extends Controller
{
    public function __construct(private CatalogService $service, private DestinationGalleryService $gallery) {}

    public function index(CatalogRequest $request)
    {
        return DestinationResource::collection($this->service->destinations($request->validated()));
    }

    public function show(CatalogRequest $request, Destination $destination)
    {
        return new DestinationResource($this->service->destination($destination));
    }

    public function store(DestinationGalleryRequest $request)
    {
        return response()->json(['data' => $this->gallery->save($request->validated(), $request->user())], 201);
    }

    public function update(DestinationGalleryRequest $request, Destination $destination)
    {
        if ($request->has('gallery_manifest') || $request->hasFile('image') || $request->hasFile('images')) {
            return response()->json(['data' => $this->gallery->save($request->validated(), $request->user(), $destination)]);
        }

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
        return new DestinationResource($destination->load(['category', 'activeFacilities', 'images'])->loadCount('reviews as rating_count')->loadAvg('reviews as rating_average', 'rating'));
    }

    public function image(CatalogRequest $request, Destination $destination)
    {
        return $this->service->image($destination, $request->routeIs('destinations.image.admin'));
    }

    public function galleryImage(CatalogRequest $request, Destination $destination, DestinationImage $image, string $variant): Response
    {
        $admin = $request->routeIs('admin.destinations.gallery.image');
        if (! $admin) {
            $this->service->destination($destination);
        }
        abort_unless($image->destination_guid === $destination->guid, 404);
        $path = $image->{$variant.'_path'};
        $disk = Storage::disk($image->storage_disk);
        abort_unless($disk->exists($path), 404);

        return $disk->response($path, null, ['Cache-Control' => 'private, max-age=86400, immutable', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function updateImage(DestinationGalleryRequest $request, Destination $destination)
    {
        return response()->json(['data' => $this->gallery->save($request->validated(), $request->user(), $destination)]);
    }
}
