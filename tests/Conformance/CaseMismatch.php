<?php

declare(strict_types=1);

namespace OscarAccountant\Tests\Conformance;

/**
 * The client broke the case: a request that does not match, one request too many, an empty source.
 */
final class CaseMismatch extends \AssertionError {}
