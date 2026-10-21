<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Verification;

use Jul6Art\DevTools\Resources;
use Jul6Art\DevTools\Tracking\AtomicFileWriter;
use Jul6Art\DevTools\Tracking\ReadsDocumentFiles;
use Jul6Art\DevTools\Xml\DomBuilder;
use Jul6Art\DevTools\Xml\InvalidXml;
use Jul6Art\DevTools\Xml\SafeXmlLoader;

/**
 * Reads the verdict the verifier gave and reads and writes the outcome DevTools retained
 * (`resources/schemas/verification.xsd`).
 */
final readonly class XmlVerificationStore
{
    use ReadsDocumentFiles;

    public const string NAMESPACE = 'https://github.com/jul6art/devtools/schema/verification/1';

    private const int SCHEMA_VERSION = 1;

    private DomBuilder $dom;

    public function __construct(private AtomicFileWriter $writer = new AtomicFileWriter(), private SafeXmlLoader $loader = new SafeXmlLoader())
    {
        $this->dom = new DomBuilder(self::NAMESPACE);
    }

    /**
     * @return array{VerificationVerdict, list<Finding>}
     *
     * @throws InvalidXml when the verdict is not one
     */
    public function readVerdict(string $path): array
    {
        $root = $this->root($path, 'verification');

        return [VerificationVerdict::from($root->getAttribute('verdict')), $this->findings($root)];
    }

    /**
     * @throws InvalidXml when the outcome is not one
     */
    public function readOutcome(string $path): Outcome
    {
        $root = $this->root($path, 'outcome');

        return new Outcome(
            VerificationVerdict::from($root->getAttribute('verdict')),
            self::parseDate($root->getAttribute('collected-at'), $path),
            array_map(static fn (\DOMElement $reason): string => $reason->textContent, $this->dom->children($root, 'reason')),
            $this->findings($root),
        );
    }

    public function writeOutcome(string $path, Outcome $outcome): void
    {
        $xml = $this->dom->document();
        $root = $this->dom->element($xml, 'outcome', ['schema-version' => (string) self::SCHEMA_VERSION, 'verdict' => $outcome->verdict->value, 'collected-at' => self::date($outcome->collectedAt)]);

        foreach ($outcome->reasons as $reason) {
            $this->dom->element($root, 'reason', text: $reason);
        }

        foreach ($outcome->findings as $finding) {
            $this->dom->element($root, 'finding', array_filter(['workflow' => $finding->workflow, 'severity' => $finding->severity, 'category' => $finding->category, 'selector' => $finding->selector], \is_string(...)), $finding->text);
        }

        $this->writer->write($path, $this->dom->toXml($xml));
    }

    private function root(string $path, string $expected): \DOMElement
    {
        $root = DomBuilder::root($this->loader->loadValidated(self::contentOf($path), $path, Resources::path('schemas/verification.xsd'), self::SCHEMA_VERSION));

        if ($expected !== $root->localName) {
            throw InvalidXml::refused($path, \sprintf('expected <%s>, found <%s>.', $expected, $root->localName));
        }

        return $root;
    }

    /**
     * @return list<Finding>
     */
    private function findings(\DOMElement $root): array
    {
        return array_map(
            static fn (\DOMElement $finding): Finding => new Finding(
                $finding->getAttribute('workflow'),
                $finding->getAttribute('severity'),
                $finding->getAttribute('category'),
                trim($finding->textContent),
                $finding->hasAttribute('selector') ? $finding->getAttribute('selector') : null,
            ),
            $this->dom->children($root, 'finding'),
        );
    }
}
