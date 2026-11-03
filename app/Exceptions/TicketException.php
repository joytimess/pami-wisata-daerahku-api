<?php

namespace App\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;
use Illuminate\Http\JsonResponse;
use RuntimeException;

class TicketException extends RuntimeException implements ShouldntReport
{
    public function __construct(public string $errorCode, string $message, public int $status = 409)
    {
        parent::__construct($message);
    }

    public function render(): JsonResponse
    {
        return response()->json(['code' => $this->errorCode, 'message' => $this->getMessage()], $this->status)
            ->header('Cache-Control', 'private, no-store');
    }
}
