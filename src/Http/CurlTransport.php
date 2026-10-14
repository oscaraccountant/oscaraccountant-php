<?php

declare(strict_types=1);

namespace OscarAccountant\Http;

use OscarAccountant\Errors\ConnectionError;
use OscarAccountant\Errors\TimeoutError;

/**
 * The default transport, on the PHP extension `curl`. It needs no Composer package.
 *
 * The timeout of an attempt covers the whole response. For a download it covers the status line and the
 * headers only: the body is then read as the caller reads it.
 */
final class CurlTransport implements Transport
{
    /**
     * @param string|null $caBundle the path of a file of CA certificates, for a host whose system store is old
     */
    public function __construct(
        private readonly ?string $caBundle = null,
    ) {}

    public function send(HttpRequest $request, bool $stream): Response
    {
        $handle = curl_init();
        $headers = new CurlHeaders();
        curl_setopt_array($handle, $this->options($request, $headers));

        return $stream ? $this->stream($handle, $request, $headers) : $this->whole($handle, $request, $headers);
    }

    /**
     * @return array<int, mixed>
     */
    private function options(HttpRequest $request, CurlHeaders $headers): array
    {
        $lines = [];
        foreach ($request->headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }
        // curl adds `Expect: 100-continue` to a large body; the API never answers it.
        $lines[] = 'Expect:';
        $options = [
            \CURLOPT_URL => $request->url,
            \CURLOPT_CUSTOMREQUEST => $request->method,
            \CURLOPT_HTTPHEADER => $lines,
            \CURLOPT_FOLLOWLOCATION => false,
            \CURLOPT_PROTOCOLS => \CURLPROTO_HTTP | \CURLPROTO_HTTPS,
            \CURLOPT_HEADERFUNCTION => $headers->collect(...),
            // The `200 Connection established` of a proxy is not the response: a download would stop on it.
            \CURLOPT_SUPPRESS_CONNECT_HEADERS => true,
            \CURLOPT_NOSIGNAL => true,
            \CURLOPT_ENCODING => '',
        ];
        if ($request->body !== null) {
            $options[\CURLOPT_POSTFIELDS] = $request->body;
        } elseif ($request->method === 'GET') {
            $options[\CURLOPT_HTTPGET] = true;
        }
        if ($this->caBundle !== null) {
            $options[\CURLOPT_CAINFO] = $this->caBundle;
        }

        return $options;
    }

    private function whole(\CurlHandle $handle, HttpRequest $request, CurlHeaders $headers): Response
    {
        curl_setopt($handle, \CURLOPT_RETURNTRANSFER, true);
        curl_setopt($handle, \CURLOPT_TIMEOUT_MS, self::milliseconds($request->timeout));
        $body = curl_exec($handle);
        $errno = curl_errno($handle);
        $error = curl_error($handle);
        if (!\is_string($body)) {
            throw self::failure($request, $errno, $error);
        }

        return new Response($headers->status, $headers->headers, $body, $headers->reason);
    }

    /**
     * Run the transfer until the headers are in, within the timeout, then give the body as a generator.
     */
    private function stream(\CurlHandle $handle, HttpRequest $request, CurlHeaders $headers): Response
    {
        $buffer = new CurlBuffer();
        curl_setopt($handle, \CURLOPT_WRITEFUNCTION, $buffer->write(...));
        $multi = curl_multi_init();
        curl_multi_add_handle($multi, $handle);
        $deadline = microtime(true) + $request->timeout;
        $close = static function () use ($multi, $handle): void {
            curl_multi_remove_handle($multi, $handle);
            curl_multi_close($multi);
        };
        while (true) {
            [$complete, $running] = $this->pump($multi, $request, $close, $headers);
            if ($complete || $running === 0) {
                break;
            }
            $left = $deadline - microtime(true);
            if ($left <= 0) {
                $close();
                throw new TimeoutError($request->method . ' ' . self::target($request) . ' timed out.');
            }
            curl_multi_select($multi, min($left, 0.2));
        }
        if (!$complete) {
            $close();
            throw new ConnectionError($request->method . ' ' . self::target($request) . ' got no response.');
        }
        $chunks = (function () use ($multi, $request, $buffer, $close, $headers, $running): \Generator {
            while (true) {
                $chunk = $buffer->take();
                if ($chunk !== '') {
                    yield $chunk;
                }
                if ($running === 0) {
                    break;
                }
                curl_multi_select($multi, 1.0);
                [, $running] = $this->pump($multi, $request, $close, $headers);
            }
            $chunk = $buffer->take();
            if ($chunk !== '') {
                yield $chunk;
            }
        })();

        return new Response($headers->status, $headers->headers, $chunks, $headers->reason, $close);
    }

    /**
     * Move the transfer on: whether the headers are in, and the number of transfers that still run.
     *
     * @param \Closure(): void $close
     *
     * @return array{bool, int}
     */
    private function pump(\CurlMultiHandle $multi, HttpRequest $request, \Closure $close, CurlHeaders $headers): array
    {
        $running = 0;
        curl_multi_exec($multi, $running);
        $this->raise($multi, $request, $close);

        return [$headers->complete(), \is_int($running) ? $running : 0];
    }

    /**
     * Raise the error of a transfer that ended with one.
     *
     * @param \Closure(): void $close
     */
    private function raise(\CurlMultiHandle $multi, HttpRequest $request, \Closure $close): void
    {
        while (($info = curl_multi_info_read($multi)) !== false) {
            $code = $info['result'];
            if (\is_int($code) && $code !== \CURLE_OK) {
                $close();
                throw self::failure($request, $code, curl_strerror($code) ?? 'unknown error');
            }
        }
    }

    private static function failure(HttpRequest $request, int $errno, string $error): ConnectionError|TimeoutError
    {
        $target = $request->method . ' ' . self::target($request);
        if ($errno === \CURLE_OPERATION_TIMEDOUT) {
            return new TimeoutError($target . ' timed out.');
        }

        return new ConnectionError($target . ' got no response: ' . $error . '.');
    }

    /**
     * The URL without the query, for a message.
     */
    private static function target(HttpRequest $request): string
    {
        return explode('?', $request->url, 2)[0];
    }

    private static function milliseconds(float $seconds): int
    {
        return max(1, (int) ceil($seconds * 1000));
    }
}
