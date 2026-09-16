<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Tests\Inspection\Adapter\Symfony;

use Jul6Art\DevTools\Config\Config;
use Jul6Art\DevTools\Inspection\Adapter\Symfony\ProcessConsoleRunner;
use Jul6Art\DevTools\Inspection\Adapter\Symfony\SymfonyAdapter;
use Jul6Art\DevTools\Inspection\Graph\PhpReferenceExtractor;
use Jul6Art\DevTools\Inspection\Graph\WorkflowBuilder;
use Jul6Art\DevTools\Inspection\Model\Workflow;
use Jul6Art\DevTools\Tests\Inspection\Graph\GraphFixture;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * The real console of symfony-minimal, in real processes: what the recordings stand in for.
 *
 * Its dependencies are installed on first run (network needed once, then Composer's cache).
 */
#[CoversNothing]
#[Group('end-to-end')]
final class RealConsoleTest extends TestCase
{
    /**
     * symfony-legacy-yaml is the same application declared the older way — routes in YAML, services in
     * XML, Symfony 7.4 — with no routing or wiring attribute left: only the console can see its entry
     * points, and they must be exactly those of the attribute version.
     */
    #[DataProvider('projects')]
    public function testTheRealConsoleGivesTheSameWorkflowsAsTheRecordings(string $project): void
    {
        self::installDependencies($project);

        $root = GraphFixture::root($project);
        $stack = GraphFixture::stack($project);
        $extractor = new PhpReferenceExtractor();
        $adapter = new SymfonyAdapter(new ProcessConsoleRunner())->extract($root, $stack, new Config(), $extractor);

        self::assertNull($adapter->fallbackCause, (string) $adapter->fallbackCause);

        $result = new WorkflowBuilder(new Config())->build($root, $stack, $adapter->candidates, $adapter->templateDirectories, $extractor)->result;

        self::assertSame(SymfonyAdapterTest::EXPECTED_WORKFLOWS, array_map(static fn (Workflow $workflow): string => (string) $workflow->id, $result->workflows));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function projects(): iterable
    {
        yield 'attributes, Symfony 8.1' => [GraphFixture::PROJECT];
        yield 'YAML routes and XML services, Symfony 7.4' => [\dirname(GraphFixture::PROJECT).'/symfony-legacy-yaml'];
    }

    public static function installDependencies(string $project): void
    {
        if (is_file($project.'/vendor/autoload.php')) {
            return;
        }

        $install = new Process(['composer', 'install', '--no-interaction', '--no-progress', '--quiet'], $project, timeout: 600);
        $install->run();

        if (!$install->isSuccessful()) {
            self::markTestSkipped('The fixture\'s dependencies could not be installed (Composer or network unavailable): '.$install->getErrorOutput());
        }
    }
}
