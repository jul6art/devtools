<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Gate;

use Jul6Art\DevTools\Resources;
use Jul6Art\DevTools\Tracking\AtomicFileWriter;
use Jul6Art\DevTools\Tracking\ReadsDocumentFiles;
use Jul6Art\DevTools\Xml\DomBuilder;
use Jul6Art\DevTools\Xml\InvalidXml;
use Jul6Art\DevTools\Xml\SafeXmlLoader;

/**
 * Reads and writes `.devtools/gate/last.xml` (`resources/schemas/gate.xsd`).
 */
final readonly class XmlGateStore
{
    use ReadsDocumentFiles;

    public const string NAMESPACE = 'https://github.com/jul6art/devtools/schema/gate/1';

    private const int SCHEMA_VERSION = 1;

    private DomBuilder $dom;

    public function __construct(private AtomicFileWriter $writer = new AtomicFileWriter(), private SafeXmlLoader $loader = new SafeXmlLoader())
    {
        $this->dom = new DomBuilder(self::NAMESPACE);
    }

    public function write(string $path, GateResult $result): void
    {
        $xml = $this->dom->document();
        $root = $this->dom->element($xml, 'gate', [
            'schema-version' => (string) self::SCHEMA_VERSION,
            'at' => self::date($result->at),
            'verdict' => $result->passed() ? 'passed' : 'failed',
            'changes' => $result->changes,
            'failure' => $result->failure(),
            'consecutive' => (string) $result->consecutive,
        ]);

        foreach ($result->changed as $file) {
            $this->dom->element($root, 'changed', ['path' => $file]);
        }

        foreach ($result->steps as $step) {
            $element = $this->dom->element($root, 'step', ['name' => $step->name, 'status' => $step->status()->value, 'duration-ms' => (string) $step->milliseconds]);

            foreach (['failure' => $step->failures, 'warning' => $step->warnings, 'note' => $step->notes] as $name => $lines) {
                foreach ($lines as $line) {
                    $this->dom->element($element, $name, text: $line);
                }
            }
        }

        $this->writer->write($path, $this->dom->toXml($xml));
    }

    /**
     * @return GateResult|null the last passage, or null when there is none or it cannot be read — then the next one
     *                         starts afresh
     */
    public function read(string $path): ?GateResult
    {
        if (!is_file($path)) {
            return null;
        }

        try {
            $root = DomBuilder::root($this->loader->loadValidated(self::contentOf($path), $path, Resources::path('schemas/gate.xsd'), self::SCHEMA_VERSION));
        } catch (InvalidXml) {
            return null;
        }

        return new GateResult(
            self::parseDate($root->getAttribute('at'), $path),
            array_map(static fn (\DOMElement $changed): string => $changed->getAttribute('path'), $this->dom->children($root, 'changed')),
            array_map(fn (\DOMElement $step): GateStep => new GateStep(
                $step->getAttribute('name'),
                self::texts($this->dom->children($step, 'failure')),
                self::texts($this->dom->children($step, 'warning')),
                self::texts($this->dom->children($step, 'note')),
                (int) $step->getAttribute('duration-ms'),
            ), $this->dom->children($root, 'step')),
            $root->getAttribute('changes'),
            (int) $root->getAttribute('consecutive'),
        );
    }

    /**
     * @param list<\DOMElement> $elements
     *
     * @return list<string>
     */
    private static function texts(array $elements): array
    {
        return array_map(static fn (\DOMElement $element): string => $element->textContent, $elements);
    }
}
