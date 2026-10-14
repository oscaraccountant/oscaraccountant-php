<?php

declare(strict_types=1);

namespace OscarAccountant\Internal;

use OscarAccountant\Upload;

/**
 * A `multipart/form-data` body, built by the SDK so that every transport sends the same bytes, and a retry
 * sends them again.
 *
 * @internal
 */
final class Multipart
{
    /**
     * The body and its content type.
     *
     * @param array<string, string> $fields the text fields, already as text
     * @param array<string, Upload> $files
     *
     * @return array{string, string}
     */
    public static function build(array $fields, array $files, string $boundary): array
    {
        $body = '';
        foreach ($fields as $name => $value) {
            $body .= '--' . $boundary . "\r\n"
                . 'Content-Disposition: form-data; name="' . self::quote($name) . "\"\r\n\r\n"
                . $value . "\r\n";
        }
        foreach ($files as $name => $file) {
            $body .= '--' . $boundary . "\r\n"
                . 'Content-Disposition: form-data; name="' . self::quote($name) . '"; filename="' . self::quote($file->filename()) . "\"\r\n"
                . 'Content-Type: ' . self::header($file->contentType) . "\r\n\r\n"
                . $file->contents() . "\r\n";
        }
        $body .= '--' . $boundary . "--\r\n";

        return [$body, 'multipart/form-data; boundary=' . $boundary];
    }

    /**
     * A name in a quoted string, as browsers write it: a quote and a line break are percent-encoded.
     */
    private static function quote(string $value): string
    {
        return str_replace(['"', "\r", "\n"], ['%22', '%0D', '%0A'], $value);
    }

    private static function header(string $value): string
    {
        return str_replace(["\r", "\n"], '', $value);
    }
}
