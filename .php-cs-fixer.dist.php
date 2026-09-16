<?php

declare(strict_types=1);

// The analysed directories are listed rather than swept with `->in(__DIR__)->exclude('vendor')`.
//
// The other bundles of this ecosystem list theirs because Flex dropped an application skeleton into
// them on every install. DevTools does not use Flex, but it will carry fixture projects under
// `tests/Fixtures/projects/` — real mini-applications, in PHP and in other languages, whose code
// follows its own conventions and must never be rewritten by this configuration. They are excluded
// below for that reason. A directory added at the root must be added here — and in
// `phpstan.dist.neon`, which lists its paths for the same reason.
$finder = new PhpCsFixer\Finder()
    ->in(array_values(array_filter(
        [
            __DIR__.'/src',
            __DIR__.'/tests',
        ],
        is_dir(...),
    )))
    ->exclude('Fixtures/projects')
    ->append(array_values(array_filter(
        [
            __FILE__,
            __DIR__.'/bin/devtools',
            __DIR__.'/rector.php',
        ],
        is_file(...),
    )));

return new PhpCsFixer\Config()
    ->setRiskyAllowed(true)
    ->setFinder($finder)
    ->setRules([
        '@Symfony' => true,
        '@Symfony:risky' => true,
        '@PHP85Migration' => true,
        'declare_strict_types' => true,
        // Keeps the leading backslash on native calls, as the bundle sources do.
        'native_function_invocation' => [
            'include' => ['@compiler_optimized'],
            'scope' => 'namespaced',
            'strict' => true,
        ],
        'ordered_class_elements' => [
            'order' => ['use_trait', 'case', 'constant', 'property', 'construct', 'destruct', 'magic', 'phpunit', 'method'],
        ],
        'php_unit_test_case_static_method_calls' => ['call_type' => 'self'],
    ]);
