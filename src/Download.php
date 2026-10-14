<?php

declare(strict_types=1);

namespace OscarAccountant;

use OscarAccountant\Errors\UsageError;
use OscarAccountant\Http\Response;

/**
 * The bytes of a download. Read them once, with `stream()`, `contents()` or `saveTo()`.
 */
final class Download
{
    /**
     * The `Content-Type` of the response.
     */
    public readonly ?string $contentType;

    /**
     * The `Content-Length` of the response, when the server gives it.
     */
    public readonly ?int $contentLength;

    /**
     * The file name of `Content-Disposition`, without a directory part.
     */
    public readonly ?string $filename;

    /**
     * The `X-Request-Id` of the response.
     */
    public readonly ?string $requestId;

    private bool $read = false;

    /**
     * @internal the client makes it
     */
    public function __construct(
        private readonly Response $response,
    ) {
        $this->contentType = $response->header('content-type');
        $length = $response->header('content-length');
        $this->contentLength = $length !== null && ctype_digit($length) ? (int) $length : null;
        $this->filename = self::filenameOf($response->header('content-disposition'));
        $this->requestId = $response->header('x-request-id');
    }

    /**
     * The body in chunks, as they arrive.
     *
     * @return \Generator<int, string>
     */
    public function stream(): \Generator
    {
        $this->claim();
        yield from $this->response->chunks();
    }

    /**
     * The whole body.
     */
    public function contents(): string
    {
        $this->claim();

        return $this->response->body();
    }

    /**
     * Write the body to a file, chunk by chunk. The number of bytes written.
     */
    public function saveTo(string $path): int
    {
        // Claim the body first: a download read before must not empty the file.
        $this->claim();
        $file = @fopen($path, 'wb');
        if ($file === false) {
            throw new UsageError(\sprintf('The file "%s" cannot be written.', $path));
        }
        $size = 0;
        try {
            foreach ($this->response->chunks() as $chunk) {
                if (fwrite($file, $chunk) !== \strlen($chunk)) {
                    throw new UsageError(\sprintf('The file "%s" cannot be written.', $path));
                }
                $size += \strlen($chunk);
            }
        } finally {
            fclose($file);
        }

        return $size;
    }

    /**
     * Stop the download without reading the rest of the body.
     */
    public function close(): void
    {
        $this->response->close();
    }

    /**
     * The file name of a `Content-Disposition` header (`filename*` wins), without any directory part.
     */
    public static function filenameOf(?string $header): ?string
    {
        if ($header === null || $header === '') {
            return null;
        }
        $name = null;
        if (preg_match("/filename\\*\\s*=\\s*([^']*)'[^']*'([^;]+)/i", $header, $match) === 1) {
            $decoded = rawurldecode(trim($match[2]));
            $charset = strtolower(trim($match[1]));
            $name = $charset === 'iso-8859-1' ? self::latin1ToUtf8($decoded) : $decoded;
        } elseif (preg_match('/filename\s*=\s*"((?:[^"\\\\]|\\\\.)*)"/i', $header, $match) === 1) {
            $name = str_replace(['\\\\', '\\"'], ['\\', '"'], $match[1]);
        } elseif (preg_match('/filename\s*=\s*([^;\s]+)/i', $header, $match) === 1) {
            $name = $match[1];
        }
        if (!\is_string($name) || $name === '') {
            return null;
        }
        $parts = explode('/', str_replace('\\', '/', $name));
        $base = end($parts);

        return $base !== '' ? $base : null;
    }

    private static function latin1ToUtf8(string $text): string
    {
        $out = '';
        foreach (str_split($text) as $char) {
            $byte = \ord($char);
            $out .= $byte < 0x80 ? $char : \chr(0xC0 | ($byte >> 6)) . \chr(0x80 | ($byte & 0x3F));
        }

        return $out;
    }

    private function claim(): void
    {
        if ($this->read) {
            throw new UsageError('The body of a download can be read once only.');
        }
        $this->read = true;
    }
}
