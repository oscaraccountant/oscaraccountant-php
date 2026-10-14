<?php

declare(strict_types=1);

namespace OscarAccountant\Tests\Conformance;

use OscarAccountant\Generated\Runtime\JsonModel;

/**
 * Any JSON object: every field is an unknown field. The response type of a spec call.
 */
final class AnyModel extends JsonModel
{
    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data, string $path = '$'): static
    {
        return (new static())->withExtra($data, []);
    }

    protected function encode(bool $json): array
    {
        return [];
    }
}
