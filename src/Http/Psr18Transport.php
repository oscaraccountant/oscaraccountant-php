<?php

declare(strict_types=1);

namespace OscarAccountant\Http;

use OscarAccountant\Errors\ConnectionError;
use OscarAccountant\Errors\TimeoutError;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Sends through the PSR-18 client of the application, for example the client of Laravel or Symfony with its
 * proxy, its logging and its test mocks.
 *
 * PSR-18 has no timeout for one request. The timeout is the one of the client the caller built, and the
 * option `timeout` of the SDK has no effect on it. The download stream reads the body of the PSR-7 response.
 */
final class Psr18Transport implements Transport
{
    /**
     * The size of a chunk of a download, in bytes.
     */
    private const CHUNK = 65536;

    public function __construct(
        private readonly ClientInterface $client,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
    ) {}

    public function send(HttpRequest $request, bool $stream): Response
    {
        $psr = $this->requestFactory->createRequest($request->method, $request->url);
        foreach ($request->headers as $name => $value) {
            $psr = $psr->withHeader($name, $value);
        }
        if ($request->body !== null) {
            $psr = $psr->withBody($this->streamFactory->createStream($request->body));
        }
        try {
            $answer = $this->client->sendRequest($psr);
        } catch (ClientExceptionInterface $error) {
            throw self::failure($request, $error);
        }
        $headers = [];
        foreach ($answer->getHeaders() as $name => $values) {
            $headers[(string) $name] = implode(', ', $values);
        }
        $body = $answer->getBody();
        if (!$stream) {
            return new Response($answer->getStatusCode(), $headers, (string) $body, $answer->getReasonPhrase());
        }
        $chunks = (static function () use ($body): \Generator {
            while (!$body->eof()) {
                yield $body->read(self::CHUNK);
            }
        })();

        return new Response(
            $answer->getStatusCode(),
            $headers,
            $chunks,
            $answer->getReasonPhrase(),
            static function () use ($body): void {
                $body->close();
            },
        );
    }

    /**
     * A client error as the error of the SDK: a timeout when the message of the client says so. The message
     * names the class of the client error, not its message, which may repeat the URL with its query.
     */
    private static function failure(HttpRequest $request, ClientExceptionInterface $error): ConnectionError|TimeoutError
    {
        $target = $request->method . ' ' . explode('?', $request->url, 2)[0];
        $message = $error->getMessage();
        if (preg_match('/timed? ?out|timeout/i', $message) === 1) {
            return new TimeoutError($target . ' timed out.', 0, $error);
        }

        $class = strrchr('\\' . $error::class, '\\');

        return new ConnectionError($target . ' got no response: ' . substr($class !== false ? $class : '', 1) . '.', 0, $error);
    }
}
