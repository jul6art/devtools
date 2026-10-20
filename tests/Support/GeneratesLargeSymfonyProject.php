<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Tests\Support;

use Jul6Art\DevTools\Inspection\Adapter\Symfony\SymfonyAdapter;
use Jul6Art\DevTools\Inspection\AdapterResolver;
use Jul6Art\DevTools\Inspection\InspectionOptions;
use Jul6Art\DevTools\Inspection\InspectionPipeline;
use Jul6Art\DevTools\Tests\Inspection\Adapter\Symfony\RecordedConsoleRunner;
use PHPUnit\Framework\Assert;
use Symfony\Component\Filesystem\Filesystem;

/**
 * The project of specs § 8: three hundred routes sharing one service, each with its controller and its
 * template — what the performance targets of ADR-0015, 0016 and 0019 are measured on. Only the answers of
 * the console are replayed.
 */
trait GeneratesLargeSymfonyProject
{
    use UsesTemporaryDirectory;

    protected const int ROUTES = 300;

    /**
     * @return array{string, RecordedConsoleRunner}
     */
    protected function generateLargeProject(): array
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

    /**
     * The generated project, generated and inspected once.
     */
    protected function inspectedLargeProject(): string
    {
        [$project, $runner] = $this->generateLargeProject();
        $report = new InspectionPipeline(new AdapterResolver([new SymfonyAdapter($runner)]))->run(new InspectionOptions($project, noAi: true));
        Assert::assertSame([], $report->errors, implode("\n", $report->errors));

        return $project;
    }
}
