<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Tests\Performance;

use Jul6Art\DevTools\Inspection\Adapter\Symfony\SymfonyAdapter;
use Jul6Art\DevTools\Inspection\AdapterResolver;
use Jul6Art\DevTools\Inspection\InspectionOptions;
use Jul6Art\DevTools\Inspection\InspectionPipeline;
use Jul6Art\DevTools\Tests\Inspection\Adapter\Symfony\RecordedConsoleRunner;
use Jul6Art\DevTools\Tests\Support\UsesTemporaryDirectory;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Specs § 8: a re-scan without change of a 300-route project in under ten seconds, without AI — through
 * the real Symfony adapter, only the console's answers are replayed.
 */
#[CoversNothing]
#[Group('performance')]
final class RescanPerformanceTest extends TestCase
{
    use UsesTemporaryDirectory;

    private const int ROUTES = 300;

    public function testAnUnchangedThreeHundredRouteProjectIsRescannedInUnderTenSeconds(): void
    {
        [$project, $runner] = $this->generate();
        $pipeline = new InspectionPipeline(new AdapterResolver([new SymfonyAdapter($runner)]));

        $first = $pipeline->run(new InspectionOptions($project));
        self::assertSame([], $first->errors, implode("\n", $first->errors));
        self::assertSame(self::ROUTES, $first->count('created'));

        $started = microtime(true);
        $second = $pipeline->run(new InspectionOptions($project));
        $seconds = microtime(true) - $started;

        self::assertSame(self::ROUTES, $second->count('unchanged'));
        self::assertLessThan(10.0, $seconds, \sprintf('Re-scan took %.2f s.', $seconds));
        fwrite(\STDERR, \sprintf("\n[performance] re-scan of %d unchanged routes: %.2f s\n", self::ROUTES, $seconds));
    }

    /**
     * @return array{string, RecordedConsoleRunner}
     */
    private function generate(): array
    {
        $project = $this->temporaryDirectory().'/generated';
        $filesystem = new Filesystem();
        $filesystem->dumpFile($project.'/composer.json', '{"name": "acme/generated", "require": {"symfony/framework-bundle": "^8.1"}, "autoload": {"psr-4": {"App\\\\": "src/"}}}');
        $filesystem->dumpFile($project.'/src/Service/Catalog.php', "<?php\nnamespace App\\Service;\nfinal class Catalog { public function all(): array { return []; } }\n");
        $filesystem->dumpFile($project.'/templates/base.html.twig', "{% block body %}{% endblock %}\n");
        $routes = [];

        for ($i = 0; $i < self::ROUTES; ++$i) {
            $filesystem->dumpFile(\sprintf('%s/src/Controller/Page%dController.php', $project, $i), <<<PHP
                <?php
                namespace App\\Controller;
                use App\\Service\\Catalog;
                use Symfony\\Bundle\\FrameworkBundle\\Controller\\AbstractController;
                use Symfony\\Component\\HttpFoundation\\Response;
                final class Page{$i}Controller extends AbstractController
                {
                    public function __invoke(Catalog \$catalog): Response
                    {
                        return \$this->render('page/{$i}.html.twig', ['items' => \$catalog->all()]);
                    }
                }
                PHP);
            $filesystem->dumpFile(\sprintf('%s/templates/page/%d.html.twig', $project, $i), "{% extends 'base.html.twig' %}\n");
            $routes['app_page_'.$i] = ['path' => '/page/'.$i, 'method' => 'GET', 'defaults' => ['_controller' => 'App\Controller\Page'.$i.'Controller']];
        }

        return [$project, new RecordedConsoleRunner([
            'router' => (string) json_encode($routes),
            'commands' => '{"definitions": {}}',
            'handlers' => '{"definitions": {}}',
            'events' => '{}',
            'workflows' => '{"workflows": {}}',
            'access-control' => '[]',
        ])];
    }
}
