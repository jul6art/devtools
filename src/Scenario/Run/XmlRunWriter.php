<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Scenario\Run;

use Jul6Art\DevTools\Resources;
use Jul6Art\DevTools\Scenario\Visual\VisualResult;
use Jul6Art\DevTools\Tracking\AtomicFileWriter;
use Jul6Art\DevTools\Xml\DomBuilder;
use Jul6Art\DevTools\Xml\SafeXmlLoader;

/**
 * Writes `result.xml` (`resources/schemas/run.xsd`), validated before it is written like every document of
 * DevTools.
 */
final readonly class XmlRunWriter
{
    public const string NAMESPACE = 'https://github.com/jul6art/devtools/schema/run/1';

    private const int SCHEMA_VERSION = 1;

    private DomBuilder $dom;

    public function __construct(private AtomicFileWriter $writer = new AtomicFileWriter(), private SafeXmlLoader $loader = new SafeXmlLoader())
    {
        $this->dom = new DomBuilder(self::NAMESPACE);
    }

    public function write(string $path, RunResult $run): void
    {
        $this->writer->write($path, $this->serialize($run));
    }

    public function serialize(RunResult $run): string
    {
        $xml = $this->dom->document();
        $root = $this->dom->element($xml, 'run', [
            'schema-version' => (string) self::SCHEMA_VERSION,
            'started-at' => $run->startedAt->format(\DATE_ATOM),
            'base-url' => $run->baseUrl,
            'passed' => (string) $run->count(Verdict::Passed),
            'failed' => (string) $run->count(Verdict::Failed),
            'errors' => (string) $run->count(Verdict::Error),
        ]);

        foreach ($run->scenarios as $scenario) {
            $element = $this->dom->element($root, 'scenario', ['id' => $scenario->id, 'verdict' => $scenario->verdict->value, 'duration-ms' => (string) $scenario->milliseconds]);

            if (null !== $scenario->message) {
                $this->dom->element($element, 'message', text: $scenario->message);
            }

            foreach ($scenario->steps as $step) {
                $this->step($element, $step);
            }
        }

        $document = $this->dom->toXml($xml);
        $this->loader->validate($this->loader->load($document, 'result.xml'), Resources::path('schemas/run.xsd'), 'result.xml');

        return $document;
    }

    private function step(\DOMElement $scenario, StepResult $step): void
    {
        $element = $this->dom->element($scenario, 'step', ['index' => (string) $step->index, 'action' => $step->action, 'verdict' => $step->verdict->value, 'duration-ms' => (string) $step->milliseconds]);

        if (null !== $step->message) {
            $this->dom->element($element, 'message', text: $step->message);
        }

        if (null !== $step->url) {
            $this->dom->element($element, 'url', text: $step->url);
        }

        if (null !== $step->screenshot) {
            $this->dom->element($element, 'screenshot', ['path' => $step->screenshot]);
        }

        if (null !== $step->dom) {
            $this->dom->element($element, 'dom', ['path' => $step->dom]);
        }

        if ($step->visual instanceof VisualResult) {
            $this->dom->element($element, 'visual', array_filter([
                'verdict' => $step->visual->verdict->value,
                'difference' => null === $step->visual->difference ? null : (string) $step->visual->difference,
                'diff' => $step->visual->diffImage,
                'dom-diff' => $step->visual->domDiff,
            ], \is_string(...)), $step->visual->summary);
        }
    }
}
