<?php

declare(strict_types=1);

/*
 * The coverage gate: fail when the lines covered in a Clover report are below a percentage.
 *
 *     php tests/check-coverage.php build/clover.xml 90
 *
 * PHPUnit has no gate of its own. The report covers `src/` without `src/Generated/` (phpunit.xml.dist).
 */

$arguments = $_SERVER['argv'] ?? [];
$path = \is_array($arguments) && \is_string($arguments[1] ?? null) ? $arguments[1] : 'build/clover.xml';
$minimum = \is_array($arguments) && is_numeric($arguments[2] ?? null) ? (float) $arguments[2] : 90.0;

$xml = is_file($path) ? simplexml_load_file($path) : false;
if ($xml === false || !isset($xml->project->metrics)) {
    fwrite(\STDERR, "check-coverage: no Clover report at {$path}.\n");
    exit(2);
}
$metrics = $xml->project->metrics;
$statements = (int) $metrics['statements'];
$covered = (int) $metrics['coveredstatements'];
$percent = $statements === 0 ? 100.0 : 100.0 * $covered / $statements;

printf("check-coverage: %d of %d lines, %.2f percent (minimum %.0f).\n", $covered, $statements, $percent, $minimum);
if ($percent < $minimum) {
    fwrite(\STDERR, "check-coverage: the coverage is below the minimum.\n");
    exit(1);
}
