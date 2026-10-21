<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Rendering;

use Jul6Art\DevTools\Config\Config;
use Jul6Art\DevTools\Inspection\Model\WorkflowId;
use Jul6Art\DevTools\Link\XmlLinksStore;
use Jul6Art\DevTools\Scenario\ScenarioReview;
use Jul6Art\DevTools\Scenario\XmlScenarioIndexStore;
use Jul6Art\DevTools\Stack\XmlStackStore;
use Jul6Art\DevTools\Tracking\AtomicFileWriter;
use Jul6Art\DevTools\Tracking\DevToolsDirectory;
use Jul6Art\DevTools\Tracking\Index;

/**
 * `workflows.md` rebuilt from what is on disk — the stack, the index, the briefs that remain, the links —
 * never from a new analysis: for `workflows:apply`, which makes briefs disappear (ADR-0049), and for
 * `--link`, which changes the orphan calls (ADR-0038). "Not covered" is computed by the inspection and
 * persisted nowhere: it is read back from the menu itself, which neither can change.
 *
 * Before the first inspection there is no menu, and nothing to correct; without the stack, the menu cannot
 * be rendered, and what was already written is not worth a failure over it.
 */
final readonly class MenuRefresher
{
    public function __construct(private AtomicFileWriter $writer = new AtomicFileWriter())
    {
    }

    public function refresh(DevToolsDirectory $directory, Index $index, Config $config): void
    {
        if (!is_file($directory->menuFile()) || !is_file($directory->stackFile())) {
            return;
        }

        $uncovered = MenuRenderer::uncoveredIn((string) file_get_contents($directory->menuFile()));
        $pending = array_map(static fn (string $brief): WorkflowId => new WorkflowId(substr(basename($brief), \strlen('page.'), -\strlen('.brief.xml'))), glob($directory->path('pending/page.*.brief.xml')) ?: []);
        $stacks = new XmlStackStore()->read($directory->stackFile());
        $scenarios = ScenarioReview::attention(new XmlScenarioIndexStore()->read($directory->scenarioIndexFile()), $index);
        $links = new XmlLinksStore()->read($directory->path(XmlLinksStore::FILE));

        $this->writer->write($directory->menuFile(), new MenuRenderer()->render($stacks, $index, $uncovered, $pending, $config->customTypes, $directory->docs, $scenarios, is_file($directory->root->absolute($directory->docs.'/glossary.md')), $links));
    }
}
