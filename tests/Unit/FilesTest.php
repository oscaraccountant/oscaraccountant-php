<?php

declare(strict_types=1);

namespace OscarAccountant\Tests\Unit;

use OscarAccountant\Download;
use OscarAccountant\Errors\UsageError;
use OscarAccountant\Http\Response;
use OscarAccountant\Internal\Multipart;
use OscarAccountant\Upload;
use PHPUnit\Framework\TestCase;

final class FilesTest extends TestCase
{
    public function testUploads(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'oscar-upload');
        self::assertIsString($path);
        file_put_contents($path, 'from disk');
        try {
            $file = Upload::fromPath($path);
            self::assertSame(basename($path), $file->filename());
            self::assertSame('application/octet-stream', $file->contentType);
            self::assertSame('from disk', $file->contents());
            self::assertSame('x.pdf', Upload::fromPath($path, 'x.pdf', 'application/pdf')->filename());

            $stream = fopen($path, 'rb');
            self::assertIsResource($stream);
            $streamed = Upload::fromStream($stream);
            self::assertSame(basename($path), $streamed->filename());
            self::assertSame('from disk', $streamed->contents());
            self::assertSame('from disk', $streamed->contents(), 'the content is read once and kept');
            fclose($stream);
        } finally {
            unlink($path);
        }
        $memory = fopen('php://memory', 'w+b');
        self::assertIsResource($memory);
        fwrite($memory, 'memory');
        rewind($memory);
        $fromMemory = Upload::fromStream($memory, contentType: 'text/plain');
        self::assertSame('upload', $fromMemory->filename());
        self::assertSame('memory', $fromMemory->contents());

        $bytes = Upload::fromString('bytes');
        self::assertSame('upload', $bytes->filename());
        self::assertSame('upload', Upload::fromString('bytes', '')->filename());
    }

    public function testUploadErrors(): void
    {
        try {
            Upload::fromPath('/nonexistent/oscar-file')->contents();
            self::fail('expected UsageError');
        } catch (UsageError) {
            $this->addToAssertionCount(1);
        }
        $closed = fopen('php://memory', 'rb');
        self::assertIsResource($closed);
        fclose($closed);
        $this->expectException(UsageError::class);
        Upload::fromStream($closed);
    }

    public function testMultipart(): void
    {
        [$body, $type] = Multipart::build(['kind' => 'invoice', "na\"me" => 'v'], ['file' => Upload::fromString('%PDF', "a\"\r\n.pdf", "application/pdf\r\nX: y")], 'b0');
        self::assertSame('multipart/form-data; boundary=b0', $type);
        self::assertSame(
            "--b0\r\nContent-Disposition: form-data; name=\"kind\"\r\n\r\ninvoice\r\n"
            . "--b0\r\nContent-Disposition: form-data; name=\"na%22me\"\r\n\r\nv\r\n"
            . "--b0\r\nContent-Disposition: form-data; name=\"file\"; filename=\"a%22%0D%0A.pdf\"\r\nContent-Type: application/pdfX: y\r\n\r\n%PDF\r\n"
            . "--b0--\r\n",
            $body,
        );
    }

    public function testFilenameOfContentDisposition(): void
    {
        self::assertNull(Download::filenameOf(null));
        self::assertNull(Download::filenameOf('attachment'));
        self::assertSame('a.pdf', Download::filenameOf('attachment; filename="a.pdf"'));
        self::assertSame('a "b".pdf', Download::filenameOf('attachment; filename="a \"b\".pdf"'));
        self::assertSame('plain.pdf', Download::filenameOf('attachment; filename=plain.pdf; size=3'));
        self::assertSame('arve €.pdf', Download::filenameOf("attachment; filename=\"fallback.pdf\"; filename*=UTF-8''arve%20%E2%82%AC.pdf"));
        self::assertSame('ä.pdf', Download::filenameOf("attachment; filename*=iso-8859-1''%E4.pdf"));
        self::assertSame('passwd', Download::filenameOf('attachment; filename="../../etc/passwd"'));
        self::assertSame('x.pdf', Download::filenameOf('attachment; filename="C:\\temp\\x.pdf"'));
        self::assertNull(Download::filenameOf('attachment; filename="dir/"'));
        self::assertNull(Download::filenameOf('attachment; filename=""'));
    }

    public function testDownloadReadsOnce(): void
    {
        $closed = false;
        $response = new Response(200, ['Content-Type' => 'application/pdf', 'Content-Length' => 'x', 'X-Request-Id' => 'r1'], ['ab', '', 'cd'], 'OK', static function () use (&$closed): void {
            $closed = true;
        });
        $download = new Download($response);
        self::assertNull($download->contentLength);
        self::assertSame('r1', $download->requestId);
        self::assertSame(['ab', 'cd'], iterator_to_array($download->stream(), false));
        self::assertTrue($closed);
        $this->expectException(UsageError::class);
        $download->contents();
    }

    public function testSaveTo(): void
    {
        $path = sys_get_temp_dir() . '/oscar-download-' . bin2hex(random_bytes(4));
        $download = new Download(new Response(200, [], ['12', '345']));
        try {
            self::assertSame(5, $download->saveTo($path));
            self::assertSame('12345', file_get_contents($path));
        } finally {
            @unlink($path);
        }
        $other = new Download(new Response(200, [], 'x'));
        $other->close();
        $this->expectException(UsageError::class);
        $other->saveTo('/nonexistent-dir/oscar/x.pdf');
    }

    public function testSaveToOfAReadDownloadKeepsTheFile(): void
    {
        $path = sys_get_temp_dir() . '/oscar-download-' . bin2hex(random_bytes(4));
        file_put_contents($path, 'old content');
        $download = new Download(new Response(200, [], 'new'));
        self::assertSame('new', $download->contents());
        try {
            $download->saveTo($path);
            self::fail('expected UsageError');
        } catch (UsageError) {
            self::assertSame('old content', file_get_contents($path));
        } finally {
            @unlink($path);
        }
    }

    public function testResponseBody(): void
    {
        $response = new Response(200, ['A' => 'b'], ['x', 'y']);
        self::assertSame(['a' => 'b'], $response->headers());
        self::assertSame(['xy', 'xy'], [$response->body(), $response->body()]);
        self::assertSame(['xy'], iterator_to_array($response->chunks(), false));
        self::assertSame([], iterator_to_array((new Response(204, [], ''))->chunks(), false));
    }
}
