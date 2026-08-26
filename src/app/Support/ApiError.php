<?php

namespace App\Support;

trait ApiError
{
    protected function error(string $code, string $message, int $status = 400, array $errors = [] )
    {
        return response()->json([
            'message' => $message,
            'code' => $code,
            'errors' => (object)$errors,
        ], $status);
    }
}
