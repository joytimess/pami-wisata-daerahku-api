<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\BookingDocumentRequest;
use App\Http\Resources\BookingDocumentResource;
use App\Services\BookingDocumentService;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BookingDocumentController extends Controller
{
    public function __construct(private BookingDocumentService $documents) {}

    public function show(BookingDocumentRequest $request, string $booking): BookingDocumentResource
    {
        return new BookingDocumentResource($this->documents->metadata($request->user(), $booking));
    }

    public function download(BookingDocumentRequest $request, string $booking, string $type): StreamedResponse
    {
        return $this->documents->download($request->user(), $booking, $type);
    }
}
