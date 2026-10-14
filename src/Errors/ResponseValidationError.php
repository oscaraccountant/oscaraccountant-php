<?php

declare(strict_types=1);

namespace OscarAccountant\Errors;

/**
 * A success response whose body does not match the type of the operation.
 */
class ResponseValidationError extends \RuntimeException implements OscarError
{
    public function __construct(
        string $message,
        public readonly int $status,
        public readonly ?string $requestId,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
