<?php

declare(strict_types=1);

namespace OscarAccountant\Tests\Unit\Support;

use OscarAccountant\Http\HttpRequest;
use OscarAccountant\Http\Response;
use OscarAccountant\Http\Transport;

/**
 * A transport that records each request and gives the next scripted answer: a response or an exception.
 */
final class ScriptedTransport implements Transport
{
    /**
     * @var list<HttpRequest>
     */
    public array $requests = [];

    /**
     * @var list<bool>
     */
    public array $streams = [];

    /**
     * @param list<Response|\Throwable> $answers
     */
    public function __construct(
        private array $answers = [],
    ) {}

    /**
     * The request of attempt `$index` (0 is the first).
     */
    public function request(int $index): HttpRequest
    {
        return $this->requests[$index] ?? throw new \LogicException('No such request.');
    }

    public function push(Response|\Throwable $answer): self
    {
        $this->answers[] = $answer;

        return $this;
    }

    public function send(HttpRequest $request, bool $stream): Response
    {
        $this->requests[] = $request;
        $this->streams[] = $stream;
        $answer = array_shift($this->answers) ?? throw new \LogicException('No answer is left.');
        if ($answer instanceof \Throwable) {
            throw $answer;
        }

        return $answer;
    }

    /**
     * A JSON response.
     *
     * @param array<string, string> $headers
     */
    public static function json(int $status, mixed $body, array $headers = []): Response
    {
        return new Response($status, $headers + ['content-type' => 'application/json'], (string) json_encode($body));
    }
}
