<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Retro;

use Jul6Art\DevTools\Resources;
use Jul6Art\DevTools\Tracking\AtomicFileWriter;
use Jul6Art\DevTools\Tracking\ReadsDocumentFiles;
use Jul6Art\DevTools\Xml\DomBuilder;
use Jul6Art\DevTools\Xml\InvalidXml;
use Jul6Art\DevTools\Xml\SafeXmlLoader;

/**
 * Reads and writes the journal of a month (`resources/schemas/journal.xsd`).
 */
final readonly class XmlJournalStore
{
    use ReadsDocumentFiles;

    public const string NAMESPACE = 'https://github.com/jul6art/devtools/schema/journal/1';

    private const int SCHEMA_VERSION = 1;

    private DomBuilder $dom;

    public function __construct(private AtomicFileWriter $writer = new AtomicFileWriter(), private SafeXmlLoader $loader = new SafeXmlLoader())
    {
        $this->dom = new DomBuilder(self::NAMESPACE);
    }

    /**
     * @param list<Task> $tasks of the month, in the order they were delivered
     */
    public function write(string $path, string $month, array $tasks): bool
    {
        $xml = $this->dom->document();
        $root = $this->dom->element($xml, 'journal', ['schema-version' => (string) self::SCHEMA_VERSION, 'month' => $month]);

        foreach ($tasks as $task) {
            $element = $this->dom->element($root, 'task', array_filter([
                'id' => $task->id,
                'started' => self::date($task->startedAt),
                'delivered' => self::date($task->deliveredAt),
                'base' => $task->base,
                'head' => $task->head,
                'attempts' => (string) $task->attempts,
            ], \is_string(...)));

            foreach (['workflow' => ['ref', $task->workflows], 'failed' => ['step', $task->steps], 'blocked' => ['rule', $task->blocked]] as $name => [$attribute, $values]) {
                foreach ($values as $value) {
                    $this->dom->element($element, $name, [$attribute => $value]);
                }
            }
        }

        $document = $this->dom->toXml($xml);
        $this->loader->validate($this->loader->load($document, $path), Resources::path('schemas/journal.xsd'), $path);

        return $this->writer->write($path, $document);
    }

    /**
     * @return list<Task> in the order they were delivered; none when the file does not exist
     *
     * @throws InvalidXml when the file is not a journal
     */
    public function read(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }

        $root = DomBuilder::root($this->loader->loadValidated(self::contentOf($path), $path, Resources::path('schemas/journal.xsd'), self::SCHEMA_VERSION));
        $values = fn (\DOMElement $task, string $name, string $attribute): array => array_map(static fn (\DOMElement $element): string => $element->getAttribute($attribute), $this->dom->children($task, $name));
        $optional = static fn (\DOMElement $task, string $name): ?string => $task->hasAttribute($name) ? $task->getAttribute($name) : null;

        return array_map(
            static fn (\DOMElement $task): Task => new Task(
                $task->getAttribute('id'),
                self::parseDate($task->getAttribute('started'), $path),
                self::parseDate($task->getAttribute('delivered'), $path),
                $optional($task, 'base'),
                $optional($task, 'head'),
                (int) $task->getAttribute('attempts'),
                $values($task, 'workflow', 'ref'),
                $values($task, 'failed', 'step'),
                $values($task, 'blocked', 'rule'),
            ),
            $this->dom->children($root, 'task'),
        );
    }
}
