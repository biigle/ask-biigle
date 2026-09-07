<?php

// The rules are the ones of biigle/core so that the module is formatted like the
// application it is installed into. Only the PHP classes are checked. The Blade
// templates live in src/resources/views and are not PHP files in the sense of these
// rules.
$finder = (new PhpCsFixer\Finder())
    ->files()
    ->in([
        __DIR__.'/src',
        __DIR__.'/tests',
    ])
    ->name('*.php')
    ->notName('*.blade.php')
    ->ignoreDotFiles(true)
    ->ignoreVCS(true);

return (new PhpCsFixer\Config())
    ->setParallelConfig(PhpCsFixer\Runner\Parallel\ParallelConfigFactory::detect())
    ->setRules([
        '@PSR2' => true,
        'ordered_imports' => true,
        'single_space_around_construct' => true,
        'array_indentation' => true,
        'array_syntax' => ['syntax' => 'short'],
        'combine_consecutive_unsets' => true,
        'class_attributes_separation' => ['elements' => ['method' => 'one', ]],
        'no_whitespace_before_comma_in_array' => true,
        'unary_operator_spaces' => true,
        'whitespace_after_comma_in_array' => true,
        'method_chaining_indentation' => true,
        'use_arrow_functions' => true,
        'no_unused_imports' => true
    ])
    ->setFinder($finder)
    ->setRiskyAllowed(true)
    ->setUsingCache(true);
