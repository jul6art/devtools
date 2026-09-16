<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Tests\Inspection\Graph;

use Jul6Art\DevTools\Inspection\Graph\PhpReferenceExtractor;
use Jul6Art\DevTools\Inspection\Graph\PhpReferences;
use Jul6Art\DevTools\Inspection\Graph\TwigReferenceExtractor;
use Jul6Art\DevTools\Tests\Support\UsesTemporaryDirectory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PhpReferenceExtractor::class)]
#[CoversClass(PhpReferences::class)]
#[CoversClass(TwigReferenceExtractor::class)]
final class PhpReferenceExtractorTest extends TestCase
{
    use UsesTemporaryDirectory;

    public function testTheWholeFileGivesEveryClassUsedAndEveryTemplateRendered(): void
    {
        $references = new PhpReferenceExtractor()->extract(GraphFixture::PROJECT.'/src/Controller/OrderController.php');

        self::assertSame([
            'App\Entity\Order',
            'App\Form\OrderType',
            'App\Repository\OrderRepository',
            'App\Service\OrderPricing',
            'Symfony\Bundle\FrameworkBundle\Controller\AbstractController',
            'Symfony\Component\HttpFoundation\Request',
            'Symfony\Component\HttpFoundation\Response',
            'Symfony\Component\Routing\Attribute\Route',
            'Symfony\Component\Security\Http\Attribute\IsGranted',
            'Symfony\Component\Workflow\WorkflowInterface',
        ], $references->classes);
        self::assertSame(['order/index.html.twig', 'order/new.html.twig', 'order/show.html.twig'], $references->templates);
        self::assertSame(['app_order_show'], $references->routes, 'Routes a controller redirects to are literal references too.');
    }

    /**
     * A route's workflow starts from its method, not from the whole controller: the list route must not
     * inherit the form of the creation route.
     */
    public function testAMethodScopeKeepsTheClassMembersAndOnlyThatMethod(): void
    {
        $references = new PhpReferenceExtractor()->extract(GraphFixture::PROJECT.'/src/Controller/OrderController.php', ['index']);

        self::assertSame([
            'App\Repository\OrderRepository',
            'Symfony\Bundle\FrameworkBundle\Controller\AbstractController',
            'Symfony\Component\HttpFoundation\Response',
            'Symfony\Component\Routing\Attribute\Route',
        ], $references->classes);
        self::assertSame(['order/index.html.twig'], $references->templates);
    }

    public function testAnUnusedImportIsNotAReference(): void
    {
        $file = $this->temporaryDirectory().'/Unused.php';
        file_put_contents($file, "<?php\nnamespace App;\nuse App\\Never\\Used;\nfinal class Unused { public function run(): \\DateTimeImmutable { return new \\DateTimeImmutable(); } }\n");

        self::assertSame(['DateTimeImmutable'], new PhpReferenceExtractor()->extract($file)->classes);
    }

    public function testAnUnparseableFileGivesAWarningInsteadOfAnException(): void
    {
        $file = $this->temporaryDirectory().'/Broken.php';
        file_put_contents($file, "<?php\nfinal class Broken {\n");

        $references = new PhpReferenceExtractor()->extract($file);

        self::assertSame([], $references->classes);
        self::assertStringContainsString('Broken.php', (string) $references->warning);
    }

    public function testLiteralRequiresAndIncludesAreResolvedFromTheIncludingFile(): void
    {
        $directory = $this->temporaryDirectory();
        file_put_contents($directory.'/page.php', <<<'PHP'
            <?php
            require __DIR__.'/../lib/db.php';
            require_once 'helpers.php';
            include '/etc/absolute.php';
            include_once __DIR__ . '/views/header.php';
            require $computed;
            require dirname(__DIR__).'/skipped.php';
            require '';
            PHP);

        self::assertSame(
            ['/etc/absolute.php', $directory.'/../lib/db.php', $directory.'/helpers.php', $directory.'/views/header.php'],
            new PhpReferenceExtractor()->extract($directory.'/page.php')->includes,
        );
    }

    public function testAPhpFileIsKnownByItsExtensionOrItsShebang(): void
    {
        $directory = $this->temporaryDirectory();
        file_put_contents($directory.'/cleanup', "#!/usr/bin/env php\n<?php\n");
        file_put_contents($directory.'/deploy', "#!/bin/sh\necho php\n");
        file_put_contents($directory.'/notes.txt', "#!/usr/bin/env php\n");
        file_put_contents($directory.'/README', "php tools live in bin/\n");

        self::assertTrue(PhpReferenceExtractor::isPhpFile($directory.'/anything.php'));
        self::assertTrue(PhpReferenceExtractor::isPhpFile($directory.'/cleanup'));
        self::assertFalse(PhpReferenceExtractor::isPhpFile($directory.'/deploy'));
        self::assertFalse(PhpReferenceExtractor::isPhpFile($directory.'/notes.txt'));
        self::assertFalse(PhpReferenceExtractor::isPhpFile($directory.'/README'));
        self::assertFalse(PhpReferenceExtractor::isPhpFile($directory.'/missing'));
    }

    public function testAFileIsParsedOnlyOnce(): void
    {
        $extractor = new PhpReferenceExtractor();

        $extractor->extract(GraphFixture::PROJECT.'/src/Entity/Order.php');
        $extractor->extract(GraphFixture::PROJECT.'/src/Entity/Order.php');
        $extractor->extract(GraphFixture::PROJECT.'/src/Controller/OrderController.php', ['new']);
        $extractor->extract(GraphFixture::PROJECT.'/src/Controller/OrderController.php', ['index']);

        self::assertSame(2, $extractor->parsedFiles());
    }

    public function testTwigReferencesAreReadFromLiteralTags(): void
    {
        $file = $this->temporaryDirectory().'/page.html.twig';
        file_put_contents($file, <<<'TWIG'
            {% extends "layout/base.html.twig" %}
            {% use 'blocks.html.twig' %}
            {% embed 'card.html.twig' with {title: 'x'} %}{% endembed %}
            {% include 'partials/_menu.html.twig' %}
            {{ include('partials/_footer.html.twig') }}
            {{ component('CartSummary', {count: 2}) }}
            {% include variable_template %}
            <a href="{{ path('app_order_show', {id: 1}) }}">{{ url("app_home") }}</a>
            TWIG);

        $references = new TwigReferenceExtractor()->extract($file);

        self::assertSame(['blocks.html.twig', 'card.html.twig', 'layout/base.html.twig', 'partials/_footer.html.twig', 'partials/_menu.html.twig'], $references->templates);
        self::assertSame(['CartSummary'], $references->components);
        self::assertSame(['app_home', 'app_order_show'], $references->routes);
        self::assertSame(['layout/base.html.twig'], $references->parents);
    }
}
