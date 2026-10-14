<?php

declare(strict_types=1);

namespace OscarAccountant\Internal;

use OscarAccountant\Http\HttpRequest;

/**
 * One call, ready to send. Every attempt sends the same URL, headers, body and `Idempotency-Key`.
 *
 * @internal
 */
final readonly class Call
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        public string $method,
        public string $url,
        public array $headers,
        public ?string $body,
        public float $timeout,
        public int $maxRetries,
        public ?string $idempotencyKey,
    ) {}

    /**
     * A `GET` or a request with an `Idempotency-Key` may be sent again. No other request is.
     */
    public function retryable(): bool
    {
        return $this->method === 'GET' || $this->idempotencyKey !== null;
    }

    /**
     * The request of one attempt, with the credential of that attempt.
     */
    public function attempt(string $token): HttpRequest
    {
        $headers = $this->headers;
        $headers['Authorization'] = 'Bearer ' . $token;
        if ($this->idempotencyKey !== null) {
            $headers['Idempotency-Key'] = $this->idempotencyKey;
        }

        return new HttpRequest($this->method, $this->url, $headers, $this->body, $this->timeout);
    }
}
