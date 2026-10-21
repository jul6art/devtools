<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Scenario\Run;

use Jul6Art\DevTools\Resources;
use Jul6Art\DevTools\Tracking\ReadsDocumentFiles;
use Jul6Art\DevTools\Xml\DomBuilder;
use Jul6Art\DevTools\Xml\InvalidXml;
use Jul6Art\DevTools\Xml\SafeXmlLoader;

/**
 * What a `result.xml` says of each scenario: its verdict, and the captures of its `screenshot` steps — never
 * those of a failure — with how each compared with its baseline (ADR-0021, ADR-0022).
 */
final readonly class XmlRunReader
{
    use ReadsDocumentFiles;

    public function __construct(private SafeXmlLoader $loader = new SafeXmlLoader())
    {
    }

    /**
     * The scenarios of a run, by identifier: their verdict, the captures of their `screenshot` steps, and the step
     * that stopped them with what it kept.
     *
     * @return array<string, array{verdict: string, captures: list<array{name: string, screenshot: string, dom: string, diff: ?string, domDiff: ?string, visual: ?string}>, failure: ?array{action: string, message: ?string, screenshot: ?string, dom: ?string}}>
     *
     * @throws InvalidXml when the file is not a result
     */
    public function read(string $path): array
    {
        $dom = new DomBuilder(XmlRunWriter::NAMESPACE);
        $root = DomBuilder::root($this->loader->loadValidated(self::contentOf($path), $path, Resources::path('schemas/run.xsd'), 1));
        $scenarios = [];

        foreach ($dom->children($root, 'scenario') as $scenario) {
            $captures = [];
            $failure = null;

            foreach ($dom->children($scenario, 'step') as $step) {
                $screenshot = $dom->optional($step, 'screenshot')?->getAttribute('path');
                $page = $dom->optional($step, 'dom')?->getAttribute('path');
                $visual = $dom->optional($step, 'visual');
                $attribute = static fn (string $name): ?string => $visual?->hasAttribute($name) ? $visual->getAttribute($name) : null;

                if ('screenshot' === $step->getAttribute('action') && null !== $screenshot && null !== $page) {
                    $captures[] = ['name' => substr($screenshot, 0, -\strlen('.png')), 'screenshot' => $screenshot, 'dom' => $page, 'diff' => $attribute('diff'), 'domDiff' => $attribute('dom-diff'), 'visual' => $attribute('verdict')];
                }

                if (null === $failure && \in_array($step->getAttribute('verdict'), ['failed', 'error'], true)) {
                    $failure = ['action' => $step->getAttribute('action'), 'message' => $dom->optional($step, 'message')?->textContent, 'screenshot' => $screenshot, 'dom' => $page];
                }
            }

            $scenarios[$scenario->getAttribute('id')] = ['verdict' => $scenario->getAttribute('verdict'), 'captures' => $captures, 'failure' => $failure];
        }

        return $scenarios;
    }
}
