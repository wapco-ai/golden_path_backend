<?php

namespace App\Exceptions;

use RuntimeException;

class PublicAuthException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $httpStatus,
        public readonly ?int $retryAfter = null,
    ) {
        // Never attach an upstream exception, request URL, OTP or token.
        parent::__construct($message);
    }
}
