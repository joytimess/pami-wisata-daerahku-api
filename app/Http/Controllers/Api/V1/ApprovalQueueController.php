<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ApprovalClaimRequest;
use App\Http\Resources\OperationalResource;
use App\Services\ApprovalQueueService;

class ApprovalQueueController extends Controller
{
    public function claim(ApprovalClaimRequest $request, string $booking, ApprovalQueueService $service): OperationalResource
    {
        return $this->mutate($request, $booking, $service, 'claim');
    }

    public function release(ApprovalClaimRequest $request, string $booking, ApprovalQueueService $service): OperationalResource
    {
        return $this->mutate($request, $booking, $service, 'release');
    }

    public function reassign(ApprovalClaimRequest $request, string $booking, ApprovalQueueService $service): OperationalResource
    {
        return $this->mutate($request, $booking, $service, 'reassign');
    }

    private function mutate(ApprovalClaimRequest $request, string $booking, ApprovalQueueService $service, string $action): OperationalResource
    {
        return new OperationalResource($service->mutate($request->user(), $booking, $action, $request->validated('idempotency_key'), $request->safe()->except('idempotency_key')));
    }
}
