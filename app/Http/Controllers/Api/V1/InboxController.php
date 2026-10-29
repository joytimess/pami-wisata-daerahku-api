<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\DeviceTokenRequest;
use App\Http\Resources\InboxNotificationResource;
use App\Services\InboxService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InboxController extends Controller
{
    public function __construct(private InboxService $service) {}

    public function index(Request $request)
    {
        return InboxNotificationResource::collection($this->service->list($request->user()));
    }

    public function unread(Request $request)
    {
        return new JsonResource(['unread_count' => $this->service->unread($request->user())]);
    }

    public function read(Request $request, string $guid)
    {
        return new InboxNotificationResource($this->service->read($request->user(), $guid));
    }

    public function register(DeviceTokenRequest $request)
    {
        $this->service->register($request->user(), $request->validated(), $request->attributes->get('api_session_guid'));

        return new JsonResource(['registered' => true]);
    }

    public function unregister(DeviceTokenRequest $request)
    {
        $this->service->unregister($request->user(), $request->validated('token'));

        return new JsonResource(['registered' => false]);
    }
}
