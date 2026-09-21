<?php

declare(strict_types=1);

use Rector\CodeQuality\Rector\ClassMethod\LocallyCalledStaticMethodToNonStaticRector;
use Rector\Config\RectorConfig;
use Rector\Php55\Rector\String_\StringClassNameToClassConstantRector;
use Rector\Php80\Rector\Class_\ClassPropertyAssignToConstructorPromotionRector;
use Rector\Renaming\Rector\Name\RenameClassRector;

return RectorConfig::configure()
    ->withPaths([
        __DIR__.'/bin/devtools',
        __DIR__.'/src',
        __DIR__.'/tests',
    ])
    // No argument: the target PHP version is read from the "php" constraint in
    // composer.json, so the rule set follows the bundle instead of drifting.
    ->withPhpSets()
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        typeDeclarations: true,
        privatization: true,
        earlyReturn: true,
        doctrineCodeQuality: true,
        symfonyCodeQuality: true,
    )
    ->withAttributesSets(symfony: true, doctrine: true, phpunit: true)
    ->withComposerBased(doctrine: true, symfony: true, phpunit: true)
    ->withSkip([
        // Real mini-projects DevTools analyses: rewriting them would change what the tests observe.
        __DIR__.'/tests/Fixtures/projects',
        // This namespace move targets `Symfony\Component\DependencyInjection\Kernel\BundleInterface`,
        // which does not exist in Symfony 8.1 — and the bundle declares `^7.4 || ^8.0`, so it cannot
        // rely on a class present on one branch only. `HttpKernel\Bundle\BundleInterface` exists on
        // both branches: that is the one we keep.
        RenameClassRector::class => [
            __DIR__.'/tests/Fixtures/TestKernel.php',
        ],
        // Class names of analysed projects are data here — what DevTools looks for — not references: turned
        // into ::class they import classes DevTools does not depend on.
        StringClassNameToClassConstantRector::class => [
            __DIR__.'/src/Inspection',
            __DIR__.'/tests',
        ],
        // Pure helpers are deliberately static: it documents that they touch no state.
        LocallyCalledStaticMethodToNonStaticRector::class,
        // Doctrine entities keep their mapped properties out of the constructor, so
        // the test fixtures stay representative of real consumer code.
        ClassPropertyAssignToConstructorPromotionRector::class => [
            __DIR__.'/tests/Fixtures/Entity',
        ],
    ])
    ->withImportNames(importShortClasses: false, removeUnusedImports: true);
