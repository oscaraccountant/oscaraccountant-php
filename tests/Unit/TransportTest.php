<?php

declare(strict_types=1);

namespace OscarAccountant\Tests\Unit;

use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Request as PsrRequest;
use OscarAccountant\Errors\ConnectionError;
use OscarAccountant\Errors\NotFoundError;
use OscarAccountant\Errors\TimeoutError;
use OscarAccountant\Http\CurlTransport;
use OscarAccountant\Http\HttpRequest;
use OscarAccountant\Http\Psr18Transport;
use OscarAccountant\Http\Transport;
use OscarAccountant\Oscar;
use OscarAccountant\Tests\Unit\Support\TestServer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TransportTest extends TestCase
{
    /**
     * @return iterable<string, array{\Closure(): Transport}>
     */
    public static function transports(): iterable
    {
        yield 'curl' => [static fn(): Transport => new CurlTransport()];
        yield 'psr-18' => [static function (): Transport {
            $factory = new HttpFactory();

            return new Psr18Transport(new Guzzle(['timeout' => 2, 'http_errors' => false, 'allow_redirects' => false]), $factory, $factory);
        }];
    }

    private static function echoed(string $body): mixed
    {
        return json_decode($body, true, 512, \JSON_THROW_ON_ERROR);
    }

    /**
     * @param \Closure(): Transport $make
     */
    #[DataProvider('transports')]
    public function testSendsTheRequestAndReadsTheResponse(\Closure $make): void
    {
        $url = TestServer::get()->url;
        $request = new HttpRequest('POST', $url . '/echo?a=1&b=x%20y', ['Content-Type' => 'application/json', 'X-Thing' => 'v'], '{"k":1}', 5.0);
        $response = $make()->send($request, false);
        self::assertSame(200, $response->status);
        self::assertSame('req_echo', $response->header('X-Request-Id'));
        $echo = self::echoed($response->body());
        self::assertIsArray($echo);
        self::assertSame('POST', $echo['method']);
        self::assertSame('/echo?a=1&b=x%20y', $echo['uri']);
        self::assertSame('{"k":1}', $echo['body']);
        self::assertIsArray($echo['headers']);
        self::assertSame('v', $echo['headers']['x-thing']);
        self::assertArrayNotHasKey('expect', $echo['headers']);

        $get = $make()->send(new HttpRequest('GET', $url . '/echo', [], null, 5.0), false);
        $echoGet = self::echoed($get->body());
        self::assertIsArray($echoGet);
        self::assertSame('GET', $echoGet['method']);
        self::assertSame('', $echoGet['body']);

        $problem = $make()->send(new HttpRequest('GET', $url . '/problem', [], null, 5.0), false);
        self::assertSame(404, $problem->status);
        self::assertSame('application/problem+json', $problem->header('content-type'));
    }

    /**
     * @param \Closure(): Transport $make
     */
    #[DataProvider('transports')]
    public function testStreamsADownload(\Closure $make): void
    {
        $url = TestServer::get()->url;
        $response = $make()->send(new HttpRequest('GET', $url . '/stream?sleep=0.3', [], null, 2.0), true);
        self::assertSame(200, $response->status);
        self::assertSame('attachment; filename="a.pdf"', $response->header('content-disposition'));
        self::assertSame('first-second', implode('', iterator_to_array($response->chunks(), false)));
        $other = $make()->send(new HttpRequest('GET', $url . '/stream', [], null, 2.0), true);
        $other->close();
        $other->close();
        $this->addToAssertionCount(1);
    }

    public function testCurlTimeoutOfADownloadCoversTheHeadersOnly(): void
    {
        $url = TestServer::get()->url;
        $response = (new CurlTransport())->send(new HttpRequest('GET', $url . '/stream?sleep=0.6', [], null, 0.3), true);
        self::assertSame('first-second', $response->body());
        $this->expectException(TimeoutError::class);
        (new CurlTransport())->send(new HttpRequest('GET', $url . '/slow-headers?sleep=1', [], null, 0.3), true);
    }

    public function testCurlDownloadThroughAProxyWaitsForTheOrigin(): void
    {
        // The `200 Connection established` of the proxy is not the response of the origin.
        $names = ['HTTPS_PROXY', 'https_proxy', 'NO_PROXY', 'no_proxy'];
        $saved = [];
        foreach ($names as $name) {
            $saved[$name] = getenv($name);
            putenv($name);
        }
        putenv('HTTPS_PROXY=' . TestServer::proxy()->url);
        putenv('https_proxy=' . TestServer::proxy()->url);
        try {
            $this->expectException(ConnectionError::class);
            (new CurlTransport())->send(new HttpRequest('GET', 'https://api.example.test/stream', [], null, 2.0), true);
        } finally {
            foreach ($saved as $name => $value) {
                putenv($value === false ? $name : $name . '=' . $value);
            }
        }
    }

    public function testCurlTimeout(): void
    {
        $url = TestServer::get()->url;
        $this->expectException(TimeoutError::class);
        $this->expectExceptionMessage('GET ' . $url . '/echo timed out.');
        (new CurlTransport())->send(new HttpRequest('GET', $url . '/echo?sleep=1', [], null, 0.2), false);
    }

    /**
     * @param \Closure(): Transport $make
     */
    #[DataProvider('transports')]
    public function testConnectionRefused(\Closure $make): void
    {
        $port = TestServer::freePort();
        foreach ([false, true] as $stream) {
            try {
                $make()->send(new HttpRequest('GET', 'http://127.0.0.1:' . $port . '/x?secret=1', [], null, 2.0), $stream);
                self::fail('expected ConnectionError');
            } catch (ConnectionError $error) {
                self::assertStringStartsWith('GET http://127.0.0.1:' . $port . '/x', $error->getMessage());
                self::assertStringNotContainsString('secret', $error->getMessage());
            }
        }
    }

    public function testPsr18TimeoutIsATimeoutError(): void
    {
        $factory = new HttpFactory();
        $error = new ConnectException('cURL error 28: Operation timed out after 200 milliseconds', new PsrRequest('GET', 'http://x'));
        $transport = new Psr18Transport(new Psr18Client($error), $factory, $factory);
        $this->expectException(TimeoutError::class);
        $transport->send(new HttpRequest('GET', 'http://x/y', [], null, 1.0), false);
    }

    public function testOscarOverCurl(): void
    {
        $url = TestServer::get()->url;
        $oscar = new Oscar(apiKey: 'osk_test', baseUrl: $url, maxRetries: 0);
        try {
            $oscar->download(new \OscarAccountant\Generated\Runtime\Request('x', 'GET', '/problem', [], [], null, false, 'binary'), null);
            self::fail('expected NotFoundError');
        } catch (NotFoundError $error) {
            self::assertSame('404 not_found: No such thing.', $error->getMessage());
        }
        $download = $oscar->download(new \OscarAccountant\Generated\Runtime\Request('x', 'GET', '/stream', [], [], null, false, 'binary'), null);
        self::assertSame('a.pdf', $download->filename);
        self::assertSame('first-second', $download->contents());
    }
}
