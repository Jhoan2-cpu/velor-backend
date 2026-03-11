<?php

namespace App\Exceptions;

use RuntimeException;

class SingleSessionConflictException extends RuntimeException
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        public readonly string $errorCode = 'SESSION_ALREADY_ACTIVE',
        string $message = 'An active session already exists for this user.',
        public readonly array $data = [],
    ) {
        parent::__construct($message);
    }
}

