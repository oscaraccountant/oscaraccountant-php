<?php

declare(strict_types=1);

namespace OscarAccountant\Http;

/**
 * The response of one attempt: the status, the headers, and the body as a string or as a stream of chunks.
 */
final class Response
{
    /**
     * @var array<string, string>
     */
    private readonly array $headers;

    /**
     * @var iterable<string>|null
     */
    private ?iterable $chunks;

    private ?string $body;

    /**
     * @param array<string, string> $headers the header names in any case; the response keeps them in lower case
     * @param string|iterable<string> $body the whole body, or its chunks (read once)
     * @param (\Closure(): void)|null $close stops a stream that the caller does not read to its end
     */
    public function __construct(
        public readonly int $status,
        array $headers,
        string|iterable $body,
        public readonly string $reason = '',
        private ?\Closure $close = null,
    ) {
        $lower = [];
        foreach ($headers as $name => $value) {
            $lower[strtolower($name)] = $value;
        }
        $this->headers = $lower;
        $this->body = \is_string($body) ? $body : null;
        $this->chunks = \is_string($body) ? null : $body;
    }

    /**
     * The headers, with names in lower case.
     *
     * @return array<string, string>
     */
    public function headers(): array
    {
        return $this->headers;
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    /**
     * The whole body. A stream is read to its end, then closed.
     */
    public function body(): string
    {
        if ($this->body === null) {
            $body = '';
            foreach ($this->chunks() as $chunk) {
                $body .= $chunk;
            }
            $this->body = $body;
        }

        return $this->body;
    }

    /**
     * The body in chunks. A stream can be read once.
     *
     * @return \Generator<int, string>
     */
    public function chunks(): \Generator
    {
        if ($this->body !== null) {
            if ($this->body !== '') {
                yield $this->body;
            }

            return;
        }
        $chunks = $this->chunks;
        $this->chunks = [];
        try {
            foreach ($chunks ?? [] as $chunk) {
                if ($chunk !== '') {
                    yield $chunk;
                }
            }
        } finally {
            $this->close();
        }
    }

    /**
     * Stop the stream, when the body is a stream that is not read to its end.
     */
    public function close(): void
    {
        $close = $this->close;
        $this->close = null;
        if ($close !== null) {
            $close();
        }
    }
}
