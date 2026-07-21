<?php

// PHP-CS-Fixer configuration (PSR-12 based). Run locally with: composer fix
// In CI this runs as an advisory check (see .github/workflows/ci.yml).
$finder = PhpCsFixer\Finder::create()
    ->in(__DIR__ . '/site/plugins/breakfast-platform/src')
    ->in(__DIR__ . '/tests');

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PSR12' => true,
        'array_syntax' => ['syntax' => 'short'],
        'no_unused_imports' => true,
        'trailing_comma_in_multiline' => ['elements' => ['arrays']],
        'declare_strict_types' => true,
    ])
    ->setFinder($finder);
