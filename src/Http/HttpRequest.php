<?php

declare(strict_types=1);

namespace OscarAccountant\Http;

/**
 * One attempt of a call, ready to send.
 */
final readonly class HttpRequest
{
    /**
     * @param string $url the absolute URL, with the query string
     * @param array<string, string> $headers the header names as the client writes them
     * @param string|null $body the bytes of the body, or null for no body
     * @param float $timeout the timeout of the attempt, in seconds
     */
    public function __construct(
        public string $method,
        public string $url,
        public array $headers,
        public ?string $body,
        public float $timeout,
    ) {}

    /**
     * The value of a header, found without regard to case.
     */
    public function header(string $name): ?string
    {
        foreach ($this->headers as $key => $value) {
            if (strcasecmp($key, $name) === 0) {
                return $value;
            }
        }

        return null;
    }
}
