<?php

namespace App\Exceptions;

use Illuminate\Validation\ValidationException;

class DomainValidationException extends ValidationException
{
    public static function forField(string $field, string $code, string $message): self
    {
        $exception = self::withMessages([$field => $message]);
        $exception->response = response()->json([
            'message' => $message, 'code' => $code, 'errors' => $exception->errors(),
        ], 422);

        return $exception;
    }
}
