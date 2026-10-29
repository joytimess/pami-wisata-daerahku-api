<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\QuotaRequest;
use App\Http\Resources\QuotaResource;
use App\Models\Destination;
use App\Services\QuotaService;

class QuotaController extends Controller
{
    public function __construct(private QuotaService $service) {}

    public function index(QuotaRequest $request, Destination $destination)
    {
        return QuotaResource::collection($this->service->list($destination, $request->validated()));
    }

    public function upsert(QuotaRequest $request, Destination $destination)
    {
        return new QuotaResource($this->service->upsert($destination, $request->validated()));
    }
}
