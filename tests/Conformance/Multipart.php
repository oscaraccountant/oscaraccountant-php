<?php

declare(strict_types=1);

namespace OscarAccountant\Tests\Conformance;

/**
 * The parts of a `multipart/form-data` body: the name, and the value or the file name, type and hash.
 */
final class Multipart
{
    /**
     * @return list<array{name: string, value?: string, filename?: string, sha256?: string, content_type?: string|null}>
     */
    public static function parse(string $contentType, string $body): array
    {
        if (preg_match('/^multipart\/form-data;\s*boundary="?([^";]+)"?/i', $contentType, $match) !== 1) {
            throw new CaseMismatch('The body is not multipart/form-data: ' . $contentType);
        }
        $delimiter = '--' . $match[1];
        $parts = [];
        foreach (\array_slice(explode($delimiter, $body), 1) as $section) {
            if (str_starts_with($section, '--')) {
                break;
            }
            $section = substr($section, 2);
            [$head, $data] = array_pad(explode("\r\n\r\n", $section, 2), 2, '');
            $data = substr($data, 0, -2);
            $headers = [];
            foreach (explode("\r\n", $head) as $line) {
                [$name, $value] = array_pad(explode(':', $line, 2), 2, '');
                $headers[strtolower(trim($name))] = trim($value);
            }
            $disposition = $headers['content-disposition'] ?? '';
            preg_match('/\bname="([^"]*)"/', $disposition, $name);
            $entry = ['name' => urldecode($name[1] ?? '')];
            if (preg_match('/\bfilename="([^"]*)"/', $disposition, $filename) === 1) {
                $entry['filename'] = urldecode($filename[1]);
                $entry['sha256'] = hash('sha256', $data);
                $entry['content_type'] = $headers['content-type'] ?? null;
            } else {
                $entry['value'] = $data;
            }
            $parts[] = $entry;
        }

        return $parts;
    }
}
