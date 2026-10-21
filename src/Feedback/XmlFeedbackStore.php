<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Feedback;

use Jul6Art\DevTools\Resources;
use Jul6Art\DevTools\Tracking\AtomicFileWriter;
use Jul6Art\DevTools\Tracking\ReadsDocumentFiles;
use Jul6Art\DevTools\Xml\DomBuilder;
use Jul6Art\DevTools\Xml\InvalidXml;
use Jul6Art\DevTools\Xml\SafeXmlLoader;

/**
 * Reads and writes one feedback (`resources/schemas/feedback.xsd`).
 */
final readonly class XmlFeedbackStore
{
    use ReadsDocumentFiles;

    public const string NAMESPACE = 'https://github.com/jul6art/devtools/schema/feedback/1';

    private const int SCHEMA_VERSION = 1;

    private DomBuilder $dom;

    public function __construct(private AtomicFileWriter $writer = new AtomicFileWriter(), private SafeXmlLoader $loader = new SafeXmlLoader())
    {
        $this->dom = new DomBuilder(self::NAMESPACE);
    }

    public function write(string $path, Feedback $feedback): bool
    {
        return $this->writer->write($path, $this->serialize($feedback));
    }

    public function serialize(Feedback $feedback): string
    {
        $xml = $this->dom->document();
        $root = $this->dom->element($xml, 'feedback', ['schema-version' => (string) self::SCHEMA_VERSION, 'id' => $feedback->id, 'status' => $feedback->status->value]);
        $reported = $feedback->reported();
        $this->dom->element($root, 'reported', array_filter(['at' => self::date($reported->at), 'by' => $reported->by->value, 'commit' => $reported->commit], \is_string(...)));
        $this->dom->element($root, 'target', self::target($feedback->target));
        $this->dom->element($root, 'category', text: $feedback->category);
        $this->dom->element($root, 'summary', text: $feedback->summary);
        $occurrences = $this->dom->element($root, 'occurrences');

        foreach ($feedback->occurrences as $occurrence) {
            $this->dom->element($occurrences, 'occurrence', array_filter(['at' => self::date($occurrence->at), 'by' => $occurrence->by->value, ...self::target($occurrence->target), 'commit' => $occurrence->commit], \is_string(...)));
        }

        if (null !== $feedback->promotedTo) {
            $this->dom->element($root, 'promoted-to', ['rule' => $feedback->promotedTo]);
        }

        $document = $this->dom->toXml($xml);
        $this->loader->validate($this->loader->load($document, $feedback->id), Resources::path('schemas/feedback.xsd'), $feedback->id);

        return $document;
    }

    /**
     * @throws InvalidXml when the file is not a feedback
     */
    public function read(string $path): Feedback
    {
        $root = DomBuilder::root($this->loader->loadValidated(self::contentOf($path), $path, Resources::path('schemas/feedback.xsd'), self::SCHEMA_VERSION));
        $occurrences = array_map(
            static fn (\DOMElement $occurrence): Occurrence => new Occurrence(self::parseDate($occurrence->getAttribute('at'), $path), FeedbackSource::from($occurrence->getAttribute('by')), self::readTarget($occurrence), self::optional($occurrence, 'commit')),
            $this->dom->children($this->dom->single($root, 'occurrences'), 'occurrence'),
        );

        if ([] === $occurrences) {
            throw InvalidXml::refused($path, 'a feedback has at least one occurrence.');
        }

        return new Feedback(
            $root->getAttribute('id'),
            FeedbackStatus::from($root->getAttribute('status')),
            self::readTarget($this->dom->single($root, 'target')),
            trim($this->dom->single($root, 'category')->textContent),
            $this->dom->single($root, 'summary')->textContent,
            $occurrences,
            $this->dom->optional($root, 'promoted-to')?->getAttribute('rule'),
        );
    }

    /**
     * @return array<string, string>
     */
    private static function target(FeedbackTarget $target): array
    {
        return array_filter(['workflow' => $target->workflow, 'selector' => $target->selector, 'file' => $target->file], \is_string(...));
    }

    private static function readTarget(\DOMElement $element): FeedbackTarget
    {
        return new FeedbackTarget(self::optional($element, 'workflow'), self::optional($element, 'selector'), self::optional($element, 'file'));
    }

    private static function optional(\DOMElement $element, string $name): ?string
    {
        return $element->hasAttribute($name) ? $element->getAttribute($name) : null;
    }
}
