<?php

declare(strict_types=1);

namespace OscarAccountant;

use OscarAccountant\Errors\UsageError;

/**
 * A file to upload in a `multipart/form-data` body.
 *
 * The content is read once, when the call is prepared, so that every retry sends the same bytes.
 */
final class Upload
{
    /**
     * The file name of an upload when the caller gives none and the content has none.
     */
    public const DEFAULT_FILENAME = 'upload';

    public const DEFAULT_CONTENT_TYPE = 'application/octet-stream';

    private ?string $content = null;

    /**
     * @param \Closure(): string $read
     */
    private function __construct(
        private readonly \Closure $read,
        private readonly ?string $name,
        public readonly string $contentType,
    ) {}

    /**
     * A file on disk. The file name is the base name of the path, unless `$filename` is given.
     */
    public static function fromPath(string $path, ?string $filename = null, ?string $contentType = null): self
    {
        $read = static function () use ($path): string {
            $content = is_file($path) && is_readable($path) ? file_get_contents($path) : false;
            if ($content === false) {
                throw new UsageError(\sprintf('The file "%s" cannot be read.', $path));
            }

            return $content;
        };

        return new self($read, $filename ?? self::basename($path), $contentType ?? self::DEFAULT_CONTENT_TYPE);
    }

    /**
     * Bytes in memory. The file name is `upload` unless `$filename` is given.
     */
    public static function fromString(string $content, ?string $filename = null, ?string $contentType = null): self
    {
        return new self(static fn(): string => $content, $filename, $contentType ?? self::DEFAULT_CONTENT_TYPE);
    }

    /**
     * An open stream, read from its current position to its end. A plain file gives its base name.
     *
     * @param resource $stream
     */
    public static function fromStream($stream, ?string $filename = null, ?string $contentType = null): self
    {
        if (!\is_resource($stream)) {
            throw new UsageError('Upload::fromStream() needs an open stream.');
        }
        if ($filename === null) {
            $meta = stream_get_meta_data($stream);
            if ($meta['wrapper_type'] === 'plainfile') {
                $filename = self::basename($meta['uri'] ?? '');
            }
        }
        $read = static function () use ($stream): string {
            $content = stream_get_contents($stream);
            if ($content === false) {
                throw new UsageError('The stream to upload cannot be read.');
            }

            return $content;
        };

        return new self($read, $filename, $contentType ?? self::DEFAULT_CONTENT_TYPE);
    }

    /**
     * The file name of the part: the name the caller gave, else the name of the file, else `upload`.
     */
    public function filename(): string
    {
        return $this->name !== null && $this->name !== '' ? $this->name : self::DEFAULT_FILENAME;
    }

    /**
     * The bytes, read on the first call only.
     */
    public function contents(): string
    {
        return $this->content ??= ($this->read)();
    }

    private static function basename(string $path): ?string
    {
        $name = basename(str_replace('\\', '/', $path));

        return $name !== '' ? $name : null;
    }
}
