<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Assertion;

use Jul6Art\DevTools\Resources;
use Jul6Art\DevTools\Tracking\AtomicFileWriter;
use Jul6Art\DevTools\Tracking\ReadsDocumentFiles;
use Jul6Art\DevTools\Xml\DomBuilder;
use Jul6Art\DevTools\Xml\InvalidXml;
use Jul6Art\DevTools\Xml\SafeXmlLoader;

/**
 * Reads and writes one assertion (`resources/schemas/assertion.xsd`) — a draft of Claude's as well as an assertion
 * of the project.
 */
final readonly class XmlAssertionStore
{
    use ReadsDocumentFiles;

    public const string NAMESPACE = 'https://github.com/jul6art/devtools/schema/assertion/1';

    private const int SCHEMA_VERSION = 1;

    private DomBuilder $dom;

    public function __construct(private AtomicFileWriter $writer = new AtomicFileWriter(), private SafeXmlLoader $loader = new SafeXmlLoader())
    {
        $this->dom = new DomBuilder(self::NAMESPACE);
    }

    public function write(string $path, Assertion $assertion): bool
    {
        return $this->writer->write($path, $this->serialize($assertion));
    }

    public function serialize(Assertion $assertion): string
    {
        $xml = $this->dom->document();
        $root = $this->dom->element($xml, 'assertion', ['schema-version' => (string) self::SCHEMA_VERSION, 'id' => $assertion->id, 'rule' => $assertion->rule]);

        foreach ($assertion->watch as $glob) {
            $this->dom->element($root, 'watch', ['glob' => $glob]);
        }

        $check = $assertion->check;
        $this->dom->element($root, $assertion->family(), array_filter(match (true) {
            $check instanceof PatternCheck => ['regex' => $check->regex, 'expect' => $check->expect->value],
            $check instanceof DomCheck => ['selector' => $check->selector, 'expect' => $check->expect->value, 'scenario' => $check->scenario, 'capture' => $check->capture],
            default => ['command' => $check->command, 'format' => $check->format->value, 'identifier' => $check->identifier],
        }, \is_string(...)));
        $this->dom->element($root, 'message', text: $assertion->message);

        $document = $this->dom->toXml($xml);
        $this->loader->validate($this->loader->load($document, $assertion->id), Resources::path('schemas/assertion.xsd'), $assertion->id);

        return $document;
    }

    /**
     * @throws InvalidXml when the file is not an assertion
     */
    public function read(string $path): Assertion
    {
        $root = DomBuilder::root($this->loader->loadValidated(self::contentOf($path), $path, Resources::path('schemas/assertion.xsd'), self::SCHEMA_VERSION));
        $watch = array_map(static fn (\DOMElement $watch): string => $watch->getAttribute('glob'), $this->dom->children($root, 'watch'));
        $optional = static fn (\DOMElement $element, string $name): ?string => $element->hasAttribute($name) ? $element->getAttribute($name) : null;

        if ([] === $watch) {
            throw InvalidXml::refused($path, 'an assertion watches at least one glob.');
        }

        $pattern = $this->dom->optional($root, 'pattern');
        $dom = $this->dom->optional($root, 'dom');
        $tool = $this->dom->optional($root, 'tool');
        $check = match (true) {
            $pattern instanceof \DOMElement => new PatternCheck($pattern->getAttribute('regex'), Expect::from($pattern->getAttribute('expect'))),
            $dom instanceof \DOMElement => new DomCheck($dom->getAttribute('selector'), Expect::from($dom->getAttribute('expect')), $dom->getAttribute('scenario'), $optional($dom, 'capture')),
            $tool instanceof \DOMElement => new ToolCheck($tool->getAttribute('command'), ToolFormat::from($tool->getAttribute('format')), $optional($tool, 'identifier')),
            default => throw InvalidXml::refused($path, 'an assertion checks a pattern, a DOM or a tool.'),
        };

        return new Assertion($root->getAttribute('id'), $root->getAttribute('rule'), $watch, $check, $this->dom->single($root, 'message')->textContent);
    }
}
