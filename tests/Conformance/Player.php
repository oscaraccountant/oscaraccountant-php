<?php

declare(strict_types=1);

namespace OscarAccountant\Tests\Conformance;

use OscarAccountant\Errors\ConnectionError;
use OscarAccountant\Errors\TimeoutError;
use OscarAccountant\Http\HttpRequest;
use OscarAccountant\Http\Response;
use OscarAccountant\Http\Transport;

/**
 * The HTTP mock: it answers the n-th request with the n-th exchange, after it checks the request.
 */
final class Player implements Transport
{
    public int $used = 0;

    /**
     * @var list<string>
     */
    public array $failures = [];

    /**
     * @param array<string, mixed> $case
     */
    public function __construct(
        private readonly array $case,
    ) {}

    public function send(HttpRequest $request, bool $stream): Response
    {
        $exchanges = Value::list($this->case['exchanges']);
        if ($this->used >= \count($exchanges)) {
            throw $this->fail(\sprintf('Request %d (%s %s) has no exchange.', $this->used + 1, $request->method, $request->url));
        }
        $exchange = Value::map($exchanges[$this->used]);
        ++$this->used;
        $found = self::requestMismatch(Value::string($this->case['origin']), Value::map($exchange['request']), $request);
        if ($found !== null) {
            throw $this->fail(\sprintf('Request %d: %s', $this->used, $found));
        }
        $fault = $exchange['fault'] ?? null;
        if ($fault === 'connection_error') {
            throw new ConnectionError('conformance: connection error');
        }
        if ($fault === 'timeout') {
            throw new TimeoutError('conformance: timeout');
        }

        return self::response(Value::map($exchange['response']), $stream);
    }

    private function fail(string $message): CaseMismatch
    {
        $this->failures[] = $message;

        return new CaseMismatch($message);
    }

    /**
     * How a request differs from the `request` of an exchange, or null.
     *
     * @param array<string, mixed> $expected
     */
    public static function requestMismatch(string $origin, array $expected, HttpRequest $request): ?string
    {
        return self::urlMismatch($origin, $expected, $request)
            ?? self::headerMismatch(Value::map($expected['headers'] ?? []), $request)
            ?? self::bodyMismatch(Value::map($expected['body']), $request)
            ?? self::timeoutMismatch($expected, $request);
    }

    /**
     * @param array<string, mixed> $expected
     */
    private static function urlMismatch(string $origin, array $expected, HttpRequest $request): ?string
    {
        $parts = parse_url($request->url);
        if ($parts === false) {
            return 'url: not a URL: ' . $request->url;
        }
        $host = $parts['host'] ?? '';
        $actualOrigin = ($parts['scheme'] ?? '') . '://' . $host . (isset($parts['port']) ? ':' . $parts['port'] : '');
        if ($actualOrigin !== $origin) {
            return \sprintf('origin: expected %s, got %s', $origin, $actualOrigin);
        }
        if ($request->method !== $expected['method']) {
            return \sprintf('method: expected %s, got %s', Value::string($expected['method']), $request->method);
        }
        $path = $parts['path'] ?? '';
        if ($path !== $expected['path']) {
            return \sprintf('path: expected %s, got %s', Value::string($expected['path']), $path);
        }
        $query = [];
        $queryString = $parts['query'] ?? '';
        if ($queryString !== '') {
            foreach (explode('&', $queryString) as $pair) {
                [$name, $value] = array_pad(explode('=', $pair, 2), 2, '');
                $query[] = [urldecode($name), urldecode($value)];
            }
        }
        $wanted = [];
        foreach (Value::list($expected['query']) as $pair) {
            $pair = Value::list($pair);
            $wanted[] = [Value::string($pair[0]), Value::string($pair[1])];
        }
        sort($query);
        sort($wanted);
        if ($query !== $wanted) {
            return \sprintf('query: expected %s, got %s', json_encode($wanted), json_encode($query));
        }

        return null;
    }

    /**
     * @param array<string, mixed> $expected
     */
    private static function headerMismatch(array $expected, HttpRequest $request): ?string
    {
        foreach ($expected as $name => $rule) {
            $actual = $request->header($name);
            if ($rule === null) {
                if ($actual !== null) {
                    return \sprintf('header %s: expected none, got %s', $name, $actual);
                }
            } elseif (\is_array($rule)) {
                $pattern = '/' . str_replace('/', '\/', Value::string($rule['pattern'])) . '/';
                if ($actual === null || preg_match($pattern, $actual) !== 1) {
                    return \sprintf('header %s: expected to match %s, got %s', $name, $pattern, $actual ?? 'none');
                }
            } elseif ($actual !== $rule) {
                return \sprintf('header %s: expected %s, got %s', $name, Value::string($rule), $actual ?? 'none');
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $expected
     */
    private static function bodyMismatch(array $expected, HttpRequest $request): ?string
    {
        $content = $request->body ?? '';
        $kind = $expected['kind'];
        if ($kind === 'none') {
            return $content === '' ? null : 'expected no body, got ' . substr($content, 0, 200);
        }
        if ($kind === 'json') {
            try {
                $actual = json_decode($content, true, 512, \JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                return 'expected a JSON body, got ' . substr($content, 0, 200);
            }

            return Matcher::mismatch($expected['value'], $actual, 'body');
        }
        $actualParts = [];
        foreach (Multipart::parse($request->header('content-type') ?? '', $content) as $part) {
            $actualParts[$part['name']] = $part;
        }
        $wanted = [];
        foreach (Value::list($expected['parts']) as $part) {
            $part = Value::map($part);
            $wanted[Value::string($part['name'])] = $part;
        }
        $names = array_keys($actualParts);
        $wantedNames = array_keys($wanted);
        sort($names);
        sort($wantedNames);
        if ($names !== $wantedNames) {
            return \sprintf('expected the parts %s, got %s', json_encode($wantedNames), json_encode($names));
        }
        foreach ($wanted as $name => $want) {
            foreach (['value', 'filename', 'sha256', 'content_type'] as $key) {
                if (\array_key_exists($key, $want) && $want[$key] !== ($actualParts[$name][$key] ?? null)) {
                    return \sprintf('part %s: expected %s %s, got %s', $name, $key, json_encode($want[$key]), json_encode($actualParts[$name][$key] ?? null));
                }
            }
        }

        return null;
    }

    /**
     * The fake sees the timeout of each attempt, so it checks it.
     *
     * @param array<string, mixed> $expected
     */
    private static function timeoutMismatch(array $expected, HttpRequest $request): ?string
    {
        if (!\array_key_exists('timeout_ms', $expected)) {
            return null;
        }
        $wanted = Value::int($expected['timeout_ms']) / 1000;
        if (abs($request->timeout - $wanted) > Runner::SLEEP_TOLERANCE) {
            return \sprintf('timeout: expected %s s, got %s s', $wanted, $request->timeout);
        }

        return null;
    }

    /**
     * The answer of an exchange, with the content type the conformance README sets when it has none.
     *
     * @param array<string, mixed> $answer
     */
    private static function response(array $answer, bool $stream): Response
    {
        $status = Value::int($answer['status']);
        $headers = array_map(Value::string(...), Value::map($answer['headers'] ?? []));
        $body = Value::map($answer['body'] ?? ['kind' => 'empty']);
        $content = '';
        $default = null;
        if ($body['kind'] === 'json') {
            $content = json_encode($body['value'], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PRESERVE_ZERO_FRACTION);
            $default = $status >= 400 ? 'application/problem+json' : 'application/json';
        } elseif ($body['kind'] === 'text') {
            $content = Value::string($body['value']);
            $default = 'text/plain; charset=utf-8';
        } elseif ($body['kind'] === 'base64') {
            $content = (string) base64_decode(Value::string($body['value']), true);
            $default = 'application/octet-stream';
        }
        if ($default !== null && !isset($headers['content-type'])) {
            $headers['content-type'] = $default;
        }
        // A stream gives the body in two chunks, so a reader that keeps only one chunk fails.
        $chunks = $stream ? str_split($content, max(1, intdiv(\strlen($content) + 1, 2))) : $content;

        return new Response($status, $headers, $chunks);
    }
}
