<?php

declare(strict_types=1);

namespace OscarAccountant\Internal;

use OscarAccountant\Generated\Runtime\JsonModel;

/**
 * The JSON of the SDK.
 *
 * `json_encode([])` gives `[]`: a body is always an object, and a model writes a map as an object. A decode
 * gives an object as a `\stdClass`, so `{}` and `[]` stay apart, and keeps a big integer as a string. The SDK
 * never encodes a float of its own: money is a string.
 *
 * @internal
 */
final class Json
{
    private const ENCODE = \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PRESERVE_ZERO_FRACTION;

    /**
     * A JSON body: a model, or an array that is written as an object.
     *
     * @param JsonModel|array<string, mixed> $body
     *
     * @throws \JsonException
     */
    public static function encodeBody(JsonModel|array $body): string
    {
        return json_encode($body instanceof JsonModel ? $body : (object) $body, self::ENCODE);
    }

    /**
     * Decode a JSON document. An object is a `\stdClass`: a model reads it as an array, and keeps it in a
     * `mixed` property and in `extra()`, so `json_encode` writes it back as an object.
     *
     * @throws \JsonException
     */
    public static function decode(string $text): mixed
    {
        try {
            return json_decode($text, false, 512, \JSON_THROW_ON_ERROR | \JSON_BIGINT_AS_STRING);
        } catch (\JsonException $error) {
            // A `\stdClass` cannot have a key that starts with NUL: such a document decodes with arrays.
            if ($error->getCode() !== \JSON_ERROR_INVALID_PROPERTY_NAME) {
                throw $error;
            }

            return json_decode($text, true, 512, \JSON_THROW_ON_ERROR | \JSON_BIGINT_AS_STRING);
        }
    }
}
