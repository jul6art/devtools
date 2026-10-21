<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Tests\Inspection\Adapter\Laravel;

use Jul6Art\DevTools\Config\Config;
use Jul6Art\DevTools\Inspection\Adapter\Laravel\LaravelAdapter;
use Jul6Art\DevTools\Inspection\Adapter\Symfony\ProcessConsoleRunner;
use Jul6Art\DevTools\Inspection\Graph\PhpReferenceExtractor;
use Jul6Art\DevTools\Inspection\Graph\WorkflowBuilder;
use Jul6Art\DevTools\Inspection\Model\Workflow;
use Jul6Art\DevTools\Tests\Inspection\Adapter\Symfony\RealConsoleTest;
use Jul6Art\DevTools\Tests\Inspection\Graph\GraphFixture;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The real Artisan of laravel-minimal, in real processes: what the recordings stand in for (ADR-0030).
 *
 * Its dependencies are installed on first run (network needed once, then Composer's cache).
 */
#[CoversNothing]
#[Group('end-to-end')]
final class RealArtisanTest extends TestCase
{
    public function testTheRealArtisanGivesTheSameWorkflowsAsTheRecordings(): void
    {
        RealConsoleTest::installDependencies(LaravelAdapterTest::PROJECT);

        $root = GraphFixture::root(LaravelAdapterTest::PROJECT);
        $stack = GraphFixture::stack(LaravelAdapterTest::PROJECT);
        $extractor = new PhpReferenceExtractor();
        $adapter = new LaravelAdapter(new ProcessConsoleRunner())->extract($root, $stack, new Config(), $extractor);

        self::assertNull($adapter->fallbackCause, (string) $adapter->fallbackCause);
        self::assertSame(3, $adapter->consoleCalls);

        $ids = array_map(static fn (Workflow $workflow): string => (string) $workflow->id, new WorkflowBuilder(new Config())->build($root, $stack, $adapter->candidates, $adapter->templateDirectories, $extractor, $adapter->mechanisms)->result->workflows);
        sort($ids, \SORT_STRING);

        self::assertSame(LaravelAdapterTest::EXPECTED_WORKFLOWS, $ids);
    }
}
