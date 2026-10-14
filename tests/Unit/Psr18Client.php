<?php

declare(strict_types=1);

namespace OscarAccountant\Tests\Unit;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * A PSR-18 client that gives a scripted response or throws a scripted error.
 */
final class Psr18Client implements ClientInterface
{
    /**
     * @var list<RequestInterface>
     */
    public array $requests = [];

    public function __construct(
        private readonly ResponseInterface|\Throwable|null $answer = null,
    ) {}

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;
        if ($this->answer instanceof \Throwable) {
            throw $this->answer;
        }

        return $this->answer ?? new \GuzzleHttp\Psr7\Response(204);
    }
}
