<?php

declare(strict_types=1);

namespace OscarAccountant\Tests\Unit;

/**
 * Unset the `OSCAR_*` variables for a test, and put them back after it.
 */
trait Environment
{
    /**
     * @var array<string, string>|null
     */
    private ?array $savedEnvironment = null;

    protected function clearOscarEnvironment(): void
    {
        $this->savedEnvironment ??= array_filter(getenv(), static fn(string $name): bool => str_starts_with($name, 'OSCAR_'), \ARRAY_FILTER_USE_KEY);
        foreach (array_keys(getenv()) as $name) {
            if (str_starts_with($name, 'OSCAR_')) {
                putenv($name);
            }
        }
    }

    protected function tearDown(): void
    {
        $saved = $this->savedEnvironment;
        if ($saved !== null) {
            $this->clearOscarEnvironment();
            foreach ($saved as $name => $value) {
                putenv($name . '=' . $value);
            }
            $this->savedEnvironment = null;
        }
        parent::tearDown();
    }
}
