<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Stack;

use Jul6Art\DevTools\Resources;
use Jul6Art\DevTools\Tracking\AtomicFileWriter;
use Jul6Art\DevTools\Xml\DomBuilder;
use Jul6Art\DevTools\Xml\InvalidXml;
use Jul6Art\DevTools\Xml\SafeXmlLoader;

/**
 * Reads and writes `.devtools/stack.xml` (`resources/schemas/stack.xsd`), locks included.
 */
final readonly class XmlStackStore
{
    public const string NAMESPACE = 'https://github.com/jul6art/devtools/schema/stack/1';

    private const int SCHEMA_VERSION = 1;

    private DomBuilder $dom;

    public function __construct(
        private AtomicFileWriter $writer = new AtomicFileWriter(),
        private SafeXmlLoader $loader = new SafeXmlLoader(),
    ) {
        $this->dom = new DomBuilder(self::NAMESPACE);
    }

    public function write(string $path, StackDocument $document): bool
    {
        return $this->writer->write($path, $this->serialize($document));
    }

    public function read(string $path): StackDocument
    {
        $content = is_file($path) ? file_get_contents($path) : false;

        return $this->unserialize(false === $content ? throw InvalidXml::refused($path, 'the file does not exist or cannot be read.') : $content, $path);
    }

    public function serialize(StackDocument $document): string
    {
        $xml = $this->dom->document();
        $root = $this->dom->element($xml, 'stacks', ['schema-version' => (string) self::SCHEMA_VERSION]);
        $this->dom->element($root, 'project', ['name' => $document->projectName, ...$this->lock($document->projectNameLocked)]);

        foreach ($document->stacks as $stack) {
            $element = $this->dom->element($root, 'stack', ['root' => $stack->root]);
            $this->dom->element($element, 'language', $this->lock($stack->isLocked('language')), $stack->language);

            if (null !== $stack->framework) {
                $this->dom->element($element, 'framework', [...(null === $stack->version ? [] : ['version' => $stack->version]), ...$this->lock($stack->isLocked('framework'))], $stack->framework);
            }

            $this->dom->element($element, 'package-manager', $this->lock($stack->isLocked('package-manager')), $stack->packageManager);
            $this->dom->element($element, 'adapter', $this->lock($stack->isLocked('adapter')), $stack->adapter);

            if (null !== $stack->knowledgeKey) {
                $this->dom->element($element, 'knowledge', $this->lock($stack->isLocked('knowledge')), $stack->knowledgeKey);
            }

            foreach (['sources' => $stack->sourceDirs, 'tests' => $stack->testDirs, 'excludes' => $stack->excludedDirs] as $name => $directories) {
                $list = $this->dom->element($element, $name, $this->lock($stack->isLocked($name)));

                foreach ($directories as $directory) {
                    $this->dom->element($list, 'dir', text: $directory);
                }
            }
        }

        return $this->dom->toXml($xml);
    }

    public function unserialize(string $xml, string $source): StackDocument
    {
        $root = DomBuilder::root($this->loader->loadValidated($xml, $source, Resources::path('schemas/stack.xsd'), self::SCHEMA_VERSION));
        $project = $this->dom->single($root, 'project');
        $stacks = [];

        foreach ($this->dom->children($root, 'stack') as $element) {
            $locked = [];

            foreach (StackProfile::LOCKABLE as $name) {
                if ('true' === $this->dom->optional($element, $name)?->getAttribute('locked')) {
                    $locked[] = $name;
                }
            }

            $framework = $this->dom->optional($element, 'framework');
            $knowledge = $this->dom->optional($element, 'knowledge');

            $stacks[] = new StackProfile(
                $element->getAttribute('root'),
                $this->dom->single($element, 'language')->textContent,
                $framework?->textContent,
                $framework instanceof \DOMElement && $framework->hasAttribute('version') ? $framework->getAttribute('version') : null,
                $this->dom->single($element, 'package-manager')->textContent,
                $this->directories($element, 'sources'),
                $this->directories($element, 'tests'),
                $this->directories($element, 'excludes'),
                $this->dom->single($element, 'adapter')->textContent,
                $knowledge?->textContent,
                $locked,
            );
        }

        return new StackDocument($project->getAttribute('name'), $stacks, 'true' === $project->getAttribute('locked'));
    }

    /**
     * @return list<string>
     */
    private function directories(\DOMElement $stack, string $name): array
    {
        return array_map(static fn (\DOMElement $directory): string => $directory->textContent, $this->dom->children($this->dom->single($stack, $name), 'dir'));
    }

    /**
     * @return array<string, string>
     */
    private function lock(bool $locked): array
    {
        return $locked ? ['locked' => 'true'] : [];
    }
}
