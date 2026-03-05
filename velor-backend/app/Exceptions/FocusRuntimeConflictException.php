<?php

namespace App\Exceptions;

use RuntimeException;

class FocusRuntimeConflictException extends RuntimeException
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        public readonly string $errorCode = 'FOCUS_RUNTIME_CONFLICT',
        string $message = 'Runtime conflict.',
        public readonly array $data = [],
    ) {
        parent::__construct($message);
    }
}

