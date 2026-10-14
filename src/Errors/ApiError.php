<?php

declare(strict_types=1);

namespace OscarAccountant\Errors;

/**
 * The API answered with an error, as an RFC 9457 problem. A subtype names the status.
 */
class ApiError extends \RuntimeException implements OscarError
{
    /**
     * The number of characters of a body that is not a problem that go in `$detail`.
     */
    public const MAX_DETAIL = 2000;

    /**
     * The stable, machine-readable code of the problem (`not_found`, `validation_error`), `unknown` when the
     * body is not a problem.
     *
     * It is always a string. `getCode()` gives it too, as for `\PDOException`: the HTTP status is `$status`.
     *
     * @var mixed a string; the type of the property of `\Exception` stays
     */
    public $code;

    /**
     * @param int|null $status the HTTP status, or null for an `AuthenticationError` raised before a request
     * @param list<array<string, mixed>> $errors one entry per invalid field
     * @param string|null $requestId the `X-Request-Id` of the response
     */
    public function __construct(
        string $message,
        public readonly ?int $status,
        string $code,
        public readonly ?string $title = null,
        public readonly ?string $detail = null,
        public readonly array $errors = [],
        public readonly ?string $requestId = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
        $this->code = $code;
    }

    /**
     * The error of a response with a status of 400 or above.
     *
     * @param array<string, string> $headers the headers, with names in lower case
     */
    public static function fromResponse(int $status, array $headers, string $body, string $reason): self
    {
        $requestId = ($headers['x-request-id'] ?? '') !== '' ? $headers['x-request-id'] : null;
        $problem = self::problem($body);
        if ($problem === null) {
            $code = 'unknown';
            $title = $reason !== '' ? $reason : ReasonPhrase::of($status);
            $title = $title !== '' ? $title : null;
            $detail = self::excerpt($body);
            $errors = [];
        } else {
            $code = \is_string($problem['code']) ? $problem['code'] : 'unknown';
            $title = \is_string($problem['title'] ?? null) ? $problem['title'] : null;
            $detail = \is_string($problem['detail'] ?? null) ? $problem['detail'] : null;
            $errors = [];
            if (\is_array($problem['errors'] ?? null)) {
                foreach ($problem['errors'] as $entry) {
                    if (\is_array($entry) && ($entry === [] || !array_is_list($entry))) {
                        /** @var array<string, mixed> $entry */
                        $errors[] = $entry;
                    }
                }
            }
        }
        $message = $status . ' ' . $code;
        $text = $detail ?? $title;
        if ($text !== null && $text !== '') {
            $message .= ': ' . $text;
        }
        if ($requestId !== null) {
            $message .= ' (request ' . $requestId . ')';
        }
        $class = self::classOf($status);

        return new $class($message, $status, $code, $title, $detail, $errors, $requestId);
    }

    /**
     * The subtype of `ApiError` for an HTTP status of 400 or above.
     *
     * @return class-string<ApiError>
     */
    public static function classOf(int $status): string
    {
        return match (true) {
            $status >= 500 => InternalServerError::class,
            $status === 400 => BadRequestError::class,
            $status === 401 => AuthenticationError::class,
            $status === 403 => PermissionDeniedError::class,
            $status === 404 => NotFoundError::class,
            $status === 409 => ConflictError::class,
            $status === 422 => UnprocessableEntityError::class,
            $status === 429 => RateLimitError::class,
            default => self::class,
        };
    }

    /**
     * The body as a problem, or null when it is not a JSON object with a string `code`.
     *
     * @return array<string, mixed>|null
     */
    private static function problem(string $body): ?array
    {
        try {
            $parsed = json_decode($body, true, 512, \JSON_THROW_ON_ERROR | \JSON_BIGINT_AS_STRING);
        } catch (\JsonException) {
            return null;
        }
        if (!\is_array($parsed) || !\is_string($parsed['code'] ?? null)) {
            return null;
        }

        /** @var array<string, mixed> $parsed */
        return $parsed;
    }

    /**
     * The first characters of a body that is not a problem, as valid UTF-8, or null when it is empty.
     */
    private static function excerpt(string $body): ?string
    {
        if ($body === '') {
            return null;
        }
        $text = htmlspecialchars_decode(htmlspecialchars($body, \ENT_SUBSTITUTE | \ENT_NOQUOTES, 'UTF-8'), \ENT_NOQUOTES);
        if (preg_match('/^.{0,' . self::MAX_DETAIL . '}/su', $text, $match) === 1) {
            return $match[0];
        }

        return $text;
    }
}
