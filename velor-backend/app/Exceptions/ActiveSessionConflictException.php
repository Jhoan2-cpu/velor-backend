<?php

namespace App\Exceptions;

use RuntimeException;

class ActiveSessionConflictException extends RuntimeException
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        public readonly string $errorCode = 'ACTIVE_SESSION_CONFLICT',
        string $message = 'Active session conflict.',
        public readonly array $data = [],
    ) {
        parent::__construct($message);
    }
}
