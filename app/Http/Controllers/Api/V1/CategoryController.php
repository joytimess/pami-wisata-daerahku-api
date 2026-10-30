<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\CatalogRequest;
use App\Http\Resources\CategoryResource;
use App\Models\DestinationCategory;
use App\Services\CatalogService;

class CategoryController extends Controller
{
    public function __construct(private CatalogService $service) {}

    public function index(CatalogRequest $request)
    {
        return CategoryResource::collection($this->service->categories($request->validated()));
    }

    public function store(CatalogRequest $request)
    {
        return new CategoryResource($this->service->categorySave($request->validated()));
    }

    public function update(CatalogRequest $request, DestinationCategory $category)
    {
        return new CategoryResource($this->service->categorySave($request->validated(), $category));
    }

    public function destroy(CatalogRequest $request, DestinationCategory $category)
    {
        return new CategoryResource($this->service->deactivate($category));
    }

    public function adminIndex(CatalogRequest $request)
    {
        return CategoryResource::collection($this->service->adminCategories($request->validated()));
    }
}
