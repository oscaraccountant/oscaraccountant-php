<?php

declare(strict_types=1);

namespace OscarAccountant\Internal;

use OscarAccountant\Errors\UsageError;
use OscarAccountant\Generated\Runtime\MultipartBody;
use OscarAccountant\Generated\Runtime\Request;
use OscarAccountant\RequestOptions;

/**
 * One call, from the request spec of a generated method to the bytes on the wire: the URL, the headers, the
 * `Idempotency-Key` and the body.
 *
 * @internal
 */
final class RequestBuilder
{
    /**
     * The headers a caller may not set, for one call or for the client.
     */
    public const PROTECTED_HEADERS = ['authorization', 'idempotency-key'];

    /**
     * The call of a generated request spec: every part that stays the same on each attempt.
     *
     * @throws UsageError
     */
    public static function prepare(Settings $settings, Request $request, ?RequestOptions $options): Call
    {
        $timeout = $options->timeout ?? $settings->timeout;
        $maxRetries = $options->maxRetries ?? $settings->maxRetries;
        if ($timeout <= 0) {
            throw new UsageError('timeout must be more than 0 seconds.');
        }
        if ($maxRetries < 0) {
            throw new UsageError('maxRetries must be 0 or more.');
        }
        $url = $settings->baseUrl . self::encodePath($request->path, $request->pathParams) . self::query($request->query);
        $idempotencyKey = null;
        if ($request->idempotent) {
            $chosen = $options?->idempotencyKey;
            $idempotencyKey = $chosen !== null && $chosen !== '' ? $chosen : ($settings->hooks->uuid)();
        }
        $accept = $request->response === 'binary' ? '*/*' : 'application/json';
        $headers = self::headers($settings, $options, $accept);
        $body = null;
        if ($request->body instanceof MultipartBody) {
            $fields = [];
            foreach ($request->body->fields as $name => $value) {
                $text = self::wireValue($value);
                if ($text !== null) {
                    $fields[$name] = $text;
                }
            }
            $boundary = 'oscar-' . bin2hex(($settings->hooks->randomBytes)(16));
            [$body, $contentType] = Multipart::build($fields, $request->body->files, $boundary);
            $headers['Content-Type'] = $contentType;
        } elseif ($request->body !== null) {
            $body = Json::encodeBody($request->body);
            $headers['Content-Type'] = 'application/json';
        }

        return new Call($request->method, $url, $headers, $body, $timeout, $maxRetries, $idempotencyKey);
    }

    /**
     * The path with each `{name}` replaced by its value, percent-encoded except the unreserved characters.
     *
     * @param array<string, string> $values
     *
     * @throws UsageError when a value is missing, empty, `.` or `..`
     */
    public static function encodePath(string $template, array $values): string
    {
        return (string) preg_replace_callback('/\{([^{}]+)\}/', static function (array $match) use ($values): string {
            $name = $match[1];
            $value = $values[$name] ?? '';
            if ($value === '' || $value === '.' || $value === '..') {
                // A URL parser removes or merges such a segment (also `%2E%2E`): the call would reach another path.
                throw new UsageError(\sprintf('The path parameter "%s" must not be missing, empty, `.` or `..`.', $name));
            }

            return rawurlencode($value);
        }, $template);
    }

    /**
     * The query string, with `?`, or an empty string. A null value is left out.
     *
     * @param array<string, scalar|\BackedEnum|\DateTimeInterface|null> $query
     */
    public static function query(array $query): string
    {
        $pairs = [];
        foreach ($query as $name => $value) {
            $text = self::wireValue($value);
            if ($text !== null) {
                $pairs[] = rawurlencode($name) . '=' . rawurlencode($text);
            }
        }

        return $pairs === [] ? '' : '?' . implode('&', $pairs);
    }

    /**
     * A query or form value as text: null is not set, a boolean is `true` or `false`, a date is `YYYY-MM-DD`.
     */
    public static function wireValue(mixed $value): ?string
    {
        return match (true) {
            $value === null => null,
            \is_bool($value) => $value ? 'true' : 'false',
            $value instanceof \BackedEnum => (string) $value->value,
            $value instanceof \DateTimeInterface => $value->format('Y-m-d'),
            \is_string($value) => $value,
            \is_int($value) => (string) $value,
            \is_float($value) => self::number($value),
            default => throw new UsageError(\sprintf('A value of type %s cannot go in a query or a form.', get_debug_type($value))),
        };
    }

    /**
     * The decimal form of a float: `0.1`, `100000000000000000000`, never an exponent.
     */
    private static function number(float $value): string
    {
        $text = (string) $value;
        if (stripos($text, 'e') === false) {
            return $text;
        }
        $fixed = rtrim(number_format($value, 20, '.', ''), '0');

        return rtrim($fixed, '.');
    }

    /**
     * @return array<string, string>
     */
    private static function headers(Settings $settings, ?RequestOptions $options, string $accept): array
    {
        $headers = ['Accept' => $accept, 'User-Agent' => $settings->userAgent];
        foreach ([$settings->defaultHeaders, $options->headers ?? []] as $extra) {
            foreach ($extra as $name => $value) {
                if (\in_array(strtolower($name), self::PROTECTED_HEADERS, true)) {
                    continue;
                }
                foreach (array_keys($headers) as $key) {
                    if (strcasecmp($key, $name) === 0) {
                        unset($headers[$key]);
                    }
                }
                $headers[$name] = $value;
            }
        }

        return $headers;
    }
}
