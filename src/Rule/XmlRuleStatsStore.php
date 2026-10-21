<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Rule;

use Jul6Art\DevTools\Resources;
use Jul6Art\DevTools\Tracking\AtomicFileWriter;
use Jul6Art\DevTools\Tracking\ReadsDocumentFiles;
use Jul6Art\DevTools\Xml\DomBuilder;
use Jul6Art\DevTools\Xml\InvalidXml;
use Jul6Art\DevTools\Xml\SafeXmlLoader;

/**
 * Reads and writes `stats/rules.xml` (`resources/schemas/rule-stats.xsd`). A count lost is a count, not a
 * reason to stop: a file that cannot be read counts from zero again.
 */
final readonly class XmlRuleStatsStore
{
    use ReadsDocumentFiles;

    public const string NAMESPACE = 'https://github.com/jul6art/devtools/schema/rule-stats/1';

    private const int SCHEMA_VERSION = 1;

    private DomBuilder $dom;

    public function __construct(private AtomicFileWriter $writer = new AtomicFileWriter(), private SafeXmlLoader $loader = new SafeXmlLoader())
    {
        $this->dom = new DomBuilder(self::NAMESPACE);
    }

    public function write(string $path, RuleStats $stats): bool
    {
        $xml = $this->dom->document();
        $root = $this->dom->element($xml, 'rule-stats', ['schema-version' => (string) self::SCHEMA_VERSION]);

        foreach ($stats->rules as $stat) {
            $this->dom->element($root, 'rule', array_filter([
                'id' => $stat->id,
                'applied' => (string) $stat->applied,
                'blocked' => (string) $stat->blocked,
                'last-applied' => null === $stat->lastApplied ? null : self::date($stat->lastApplied),
                'last-blocked' => null === $stat->lastBlocked ? null : self::date($stat->lastBlocked),
            ], \is_string(...)));
        }

        return $this->writer->write($path, $this->dom->toXml($xml));
    }

    public function read(string $path): RuleStats
    {
        if (!is_file($path)) {
            return new RuleStats();
        }

        try {
            $root = DomBuilder::root($this->loader->loadValidated(self::contentOf($path), $path, Resources::path('schemas/rule-stats.xsd'), self::SCHEMA_VERSION));
        } catch (InvalidXml) {
            return new RuleStats();
        }

        $stats = [];
        $date = static fn (\DOMElement $rule, string $name): ?\DateTimeImmutable => $rule->hasAttribute($name) ? self::parseDate($rule->getAttribute($name), $path) : null;

        foreach ($this->dom->children($root, 'rule') as $rule) {
            $id = $rule->getAttribute('id');
            $stats[$id] = new RuleStat($id, (int) $rule->getAttribute('applied'), (int) $rule->getAttribute('blocked'), $date($rule, 'last-applied'), $date($rule, 'last-blocked'));
        }

        return new RuleStats($stats);
    }
}
