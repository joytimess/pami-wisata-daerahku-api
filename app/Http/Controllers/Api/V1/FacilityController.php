<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\DestinationFacilitiesRequest;
use App\Http\Requests\FacilityRequest;
use App\Http\Resources\DestinationResource;
use App\Http\Resources\FacilityResource;
use App\Models\Destination;
use App\Models\Facility;
use App\Services\FacilityService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class FacilityController extends Controller
{
    public function __construct(private FacilityService $service) {}

    public function index(FacilityRequest $request): AnonymousResourceCollection
    {
        return FacilityResource::collection($this->service->listing($request->validated(), $request->routeIs('superadmin.facilities.*')));
    }

    public function store(FacilityRequest $request): FacilityResource
    {
        return new FacilityResource($this->service->save($request->validated()));
    }

    public function update(FacilityRequest $request, Facility $facility): FacilityResource
    {
        return new FacilityResource($this->service->save($request->validated(), $facility));
    }

    public function destroy(FacilityRequest $request, Facility $facility): FacilityResource
    {
        return new FacilityResource($this->service->save(['is_active' => false], $facility));
    }

    public function assign(DestinationFacilitiesRequest $request, Destination $destination): DestinationResource
    {
        return new DestinationResource($this->service->assign($destination, $request->validated('facility_guids')));
    }
}
