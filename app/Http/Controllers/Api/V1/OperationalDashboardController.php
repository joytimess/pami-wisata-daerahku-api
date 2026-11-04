<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\DashboardRequest;
use App\Http\Resources\OperationalResource;
use App\Services\OperationalDashboardService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class OperationalDashboardController extends Controller
{
    public function __invoke(DashboardRequest $request, OperationalDashboardService $service): mixed
    {
        $section = $request->route('section') ?? ($request->routeIs('admin.approval-queue') ? 'queue' : 'overview');
        $filters = $request->validated();
        if ($section === 'action-center' && ! isset($filters['from'])) {
            $filters['from'] = now()->utc()->toDateString();
            $filters['to'] = now()->utc()->addDays(30)->toDateString();
        }
        $filters['date_basis'] = in_array($section, ['destinations', 'visitors', 'action-center'], true) ? 'visit' : ($section === 'queue' ? 'created' : 'decision');
        $result = $service->get($request->user(), $section, $filters);
        if ($result instanceof LengthAwarePaginator) {
            return OperationalResource::collection($result)->additional(['meta' => $service->metadata($request->user(), $filters)]);
        }

        return new OperationalResource($result);
    }
}
