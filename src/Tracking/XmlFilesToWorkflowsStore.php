<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Tracking;

use Jul6Art\DevTools\Inspection\Model\FileRef;
use Jul6Art\DevTools\Inspection\Model\WorkflowId;
use Jul6Art\DevTools\Resources;
use Jul6Art\DevTools\Xml\DomBuilder;
use Jul6Art\DevTools\Xml\SafeXmlLoader;

/**
 * Reads and writes `graph/files-to-workflows.xml` (`resources/schemas/files-to-workflows.xsd`).
 */
final readonly class XmlFilesToWorkflowsStore implements FilesToWorkflowsReaderInterface, FilesToWorkflowsWriterInterface
{
    use ReadsDocumentFiles;

    public const string NAMESPACE = 'https://github.com/jul6art/devtools/schema/files-to-workflows/1';

    private const int SCHEMA_VERSION = 1;

    private DomBuilder $dom;

    public function __construct(
        private AtomicFileWriter $writer = new AtomicFileWriter(),
        private SafeXmlLoader $loader = new SafeXmlLoader(),
    ) {
        $this->dom = new DomBuilder(self::NAMESPACE);
    }

    #[\Override]
    public function write(string $path, FilesToWorkflows $document): bool
    {
        return $this->writer->write($path, $this->serialize($document));
    }

    #[\Override]
    public function read(string $path): FilesToWorkflows
    {
        return $this->unserialize(self::contentOf($path), $path);
    }

    public function serialize(FilesToWorkflows $graph): string
    {
        $xml = $this->dom->document();
        $root = $this->dom->element($xml, 'files-to-workflows', ['schema-version' => (string) self::SCHEMA_VERSION]);

        foreach ($graph->links as $path => $links) {
            $file = $this->dom->element($root, 'file', ['path' => $path]);

            foreach ($links as $link) {
                $this->dom->element($file, 'workflow', ['ref' => $link->workflow->value, 'relation' => $link->relation->value]);
            }
        }

        return $this->dom->toXml($xml);
    }

    public function unserialize(string $xml, string $source): FilesToWorkflows
    {
        $root = DomBuilder::root($this->loader->loadValidated($xml, $source, Resources::path('schemas/files-to-workflows.xsd'), self::SCHEMA_VERSION));
        $links = [];

        foreach ($this->dom->children($root, 'file') as $file) {
            // Through FileRef, so a path escaping the project is refused on read like everywhere else.
            $path = new FileRef($file->getAttribute('path'))->path;

            foreach ($this->dom->children($file, 'workflow') as $workflow) {
                $links[$path][] = new FileLink(new WorkflowId($workflow->getAttribute('ref')), FileRelation::from($workflow->getAttribute('relation')));
            }
        }

        return new FilesToWorkflows($links);
    }
}
