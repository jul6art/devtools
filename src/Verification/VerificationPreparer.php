<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Verification;

use Jul6Art\DevTools\Impact\ImpactedWorkflow;
use Jul6Art\DevTools\Impact\ImpactResolver;
use Jul6Art\DevTools\Inspection\Model\WorkflowTypeRegistry;
use Jul6Art\DevTools\Project\ProjectRoot;
use Jul6Art\DevTools\Resources;
use Jul6Art\DevTools\Scenario\Run\XmlRunReader;
use Jul6Art\DevTools\Scenario\ScenarioGenerator;
use Jul6Art\DevTools\Tracking\DevToolsDirectory;
use Jul6Art\DevTools\Tracking\XmlTrackingStore;
use Jul6Art\DevTools\Xml\DomBuilder;
use Jul6Art\DevTools\Xml\SafeXmlLoader;
use Symfony\Component\Filesystem\Filesystem;

/**
 * `verify:prepare` (ADR-0024): the folder a verifier judges a change on, in a fresh context — the routes and
 * components the change reaches, their pages, their scenarios, the captures of the last run, the baselines and
 * the diffs, copied; their code, named. Nothing of the conversation that wrote the change, nor of a commit
 * message.
 */
final readonly class VerificationPreparer
{
    public const string NAMESPACE = 'https://github.com/jul6art/devtools/schema/verification-brief/1';

    public function __construct(private Filesystem $filesystem = new Filesystem())
    {
    }

    /**
     * The change as it is now: its files and their content. A folder prepared for it is still the right one
     * while this does not move (ADR-0023).
     *
     * @param list<string> $changed
     */
    public static function fingerprint(ProjectRoot $root, array $changed): string
    {
        $files = array_values(array_unique(array_map(static fn (string $file): string => $root->relative($root->absolute($file)), $changed)));
        sort($files, \SORT_STRING);

        return hash('sha256', implode("\n", array_map(static fn (string $file): string => $file.':'.(is_file($root->absolute($file)) ? (string) hash_file('sha256', $root->absolute($file)) : 'deleted'), $files)));
    }

    /**
     * The latest folder prepared for this state of the change, if any.
     */
    public static function folderOf(DevToolsDirectory $directory, string $fingerprint): ?string
    {
        $folders = glob($directory->path('pending/verification.*'), \GLOB_ONLYDIR) ?: [];
        rsort($folders, \SORT_STRING);

        foreach ($folders as $folder) {
            $manifest = is_file($folder.'/manifest.xml') ? (string) file_get_contents($folder.'/manifest.xml') : '';

            if (str_contains($manifest, 'fingerprint="'.$fingerprint.'"')) {
                return $folder;
            }
        }

        return null;
    }

    /**
     * @param list<string> $changed the files of the change, relative to the project
     *
     * @return string|null the folder, or null when the change reaches no route and no component
     */
    public function prepare(DevToolsDirectory $directory, WorkflowTypeRegistry $types, array $changed, \DateTimeImmutable $at): ?string
    {
        $impact = new ImpactResolver($types)->resolve($directory, $changed);
        $workflows = array_values(array_filter($impact->workflows, static fn (ImpactedWorkflow $workflow): bool => \in_array($workflow->type->name, ScenarioGenerator::TYPES, true)));

        if ([] === $workflows) {
            return null;
        }

        $folder = $directory->path('pending/verification.'.$at->format('Y-m-d-His'));
        $this->filesystem->remove($folder);
        $this->filesystem->mkdir($folder);

        $runs = glob($directory->path('runs/*/result.xml')) ?: [];
        sort($runs, \SORT_STRING);
        $result = array_last($runs);
        $run = null === $result ? [] : new XmlRunReader()->read($result);

        $dom = new DomBuilder(self::NAMESPACE);
        $xml = $dom->document();
        $root = $dom->element($xml, 'verification-brief', array_filter(['schema-version' => '1', 'prepared-at' => $at->format(\DATE_ATOM), 'fingerprint' => self::fingerprint($directory->root, $changed), 'run' => null === $result ? null : basename(\dirname($result))], \is_string(...)));

        foreach (array_values(array_unique(array_map(static fn (string $file): string => $directory->root->relative($directory->root->absolute($file)), $changed))) as $file) {
            $dom->element($root, 'changed', ['path' => $file]);
        }

        $tracking = new XmlTrackingStore($types);

        foreach ($workflows as $workflow) {
            $page = $directory->root->absolute($workflow->page);
            $element = $dom->element($root, 'workflow', array_filter([
                'id' => $workflow->id->value,
                'title' => $workflow->title,
                'relation' => $workflow->relation->value,
                'page' => is_file($page) ? $this->copy($page, $folder, 'pages/'.$workflow->id->value.'.md') : null,
            ], \is_string(...)));

            foreach ($tracking->read($directory->trackingFile($workflow->type, $workflow->id))->files as $file) {
                $dom->element($element, 'file', ['path' => $file->file->path]);
            }

            $this->scenarios($directory, $folder, $workflow, $run, null === $result ? '' : \dirname($result), $dom, $element);
        }

        foreach (Finding::CATEGORIES as $category) {
            $dom->element($root, 'category', text: $category);
        }

        $dom->element($root, 'verdict', ['path' => $directory->root->relative($folder).'/verdict.xml']);
        $manifest = $dom->toXml($xml);
        new SafeXmlLoader()->validate(new SafeXmlLoader()->load($manifest, 'manifest.xml'), Resources::path('schemas/verification-brief.xsd'), 'manifest.xml');
        file_put_contents($folder.'/manifest.xml', $manifest);

        return $folder;
    }

    /**
     * @param array<string, array{verdict: string, captures: list<array{name: string, screenshot: string, dom: string, diff: ?string, domDiff: ?string, visual: ?string}>}> $run
     */
    private function scenarios(DevToolsDirectory $directory, string $folder, ImpactedWorkflow $workflow, array $run, string $artifacts, DomBuilder $dom, \DOMElement $element): void
    {
        $files = glob($directory->path('scenarios/'.$workflow->id->value.'/*.xml')) ?: [];
        sort($files, \SORT_STRING);
        $captures = [];

        foreach ($files as $file) {
            $id = $workflow->id->value.'/'.basename($file, '.xml');
            $dom->element($element, 'scenario', array_filter(['id' => $id, 'path' => $this->copy($file, $folder, 'scenarios/'.$id.'.xml'), 'verdict' => $run[$id]['verdict'] ?? null], \is_string(...)));
            $captures = [...$captures, ...$run[$id]['captures'] ?? []];
        }

        foreach ($captures as $capture) {
            $baseline = $directory->baselinesDirectory().'/'.$capture['name'];
            $screenshot = $this->copy($artifacts.'/'.$capture['screenshot'], $folder, 'run/'.$capture['screenshot']);
            $page = $this->copy($artifacts.'/'.$capture['dom'], $folder, 'run/'.$capture['dom']);

            // A run cleaned up by hand: what is gone cannot be judged.
            if (null === $screenshot || null === $page) {
                continue;
            }

            $dom->element($element, 'capture', array_filter([
                'name' => $capture['name'],
                'screenshot' => $screenshot,
                'dom' => $page,
                'baseline' => is_file($baseline.'.png') ? $this->copy($baseline.'.png', $folder, 'baselines/'.$capture['name'].'.png') : null,
                'diff' => null === $capture['diff'] ? null : $this->copy($artifacts.'/'.$capture['diff'], $folder, 'run/'.$capture['diff']),
                'dom-diff' => null === $capture['domDiff'] ? null : $this->copy($artifacts.'/'.$capture['domDiff'], $folder, 'run/'.$capture['domDiff']),
                'visual' => $capture['visual'],
            ], \is_string(...)));

            if (is_file($baseline.'.dom.html')) {
                $this->copy($baseline.'.dom.html', $folder, 'baselines/'.$capture['name'].'.dom.html');
            }
        }
    }

    /**
     * @return string|null where the copy is, relative to the folder; null when there was nothing to copy
     */
    private function copy(string $source, string $folder, string $target): ?string
    {
        if (!is_file($source)) {
            return null;
        }

        $this->filesystem->copy($source, $folder.'/'.$target, true);

        return $target;
    }
}
