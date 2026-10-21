<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Rule;

use Jul6Art\DevTools\Tracking\AtomicFileWriter;
use Jul6Art\DevTools\Tracking\DevToolsDirectory;

/**
 * The rules of a project (ADR-0026): `.devtools/rules/<id>.xml`, their index, and what they did.
 */
final readonly class RuleRepository
{
    public function __construct(private DevToolsDirectory $directory, private AtomicFileWriter $writer = new AtomicFileWriter())
    {
    }

    public function index(): RuleIndex
    {
        return new XmlRuleIndexStore($this->writer)->read($this->indexFile());
    }

    /**
     * @throws RuleNotFound
     */
    public function get(string $id): Rule
    {
        $entry = $this->index()->entry($id) ?? throw RuleNotFound::id($id);

        return new XmlRuleStore($this->writer)->read($this->directory->rulesDirectory().'/'.$entry->path());
    }

    /**
     * Writes the rule and its line of the index.
     */
    public function save(Rule $rule): void
    {
        new XmlRuleStore($this->writer)->write($this->directory->rulesDirectory().'/'.$rule->path(), $rule);
        new XmlRuleIndexStore($this->writer)->write($this->indexFile(), $this->index()->with(RuleIndexEntry::of($rule)));
    }

    public function stats(): RuleStats
    {
        return new XmlRuleStatsStore($this->writer)->read($this->directory->ruleStatsFile());
    }

    public function saveStats(RuleStats $stats): void
    {
        new XmlRuleStatsStore($this->writer)->write($this->directory->ruleStatsFile(), $stats);
    }

    private function indexFile(): string
    {
        return $this->directory->rulesDirectory().'/index.xml';
    }
}
