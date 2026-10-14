<?php

declare(strict_types=1);

// The style of the SDK: PER Coding Style 2.0, and strict types in every file.
return (new PhpCsFixer\Config())
    ->setRules([
        '@PER-CS2.0' => true,
        'declare_strict_types' => true,
    ])
    ->setRiskyAllowed(true)
    ->setFinder(
        PhpCsFixer\Finder::create()
            ->in([__DIR__ . '/src', __DIR__ . '/tests'])
            ->append([__FILE__]),
    );
