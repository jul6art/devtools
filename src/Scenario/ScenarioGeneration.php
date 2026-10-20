<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Scenario;

/**
 * What `scenarios:generate` did (ADR-0020).
 */
final class ScenarioGeneration
{
    /**
     * @var list<string> scenarios written from the facts, new or rewritten
     */
    public array $written = [];

    /**
     * @var list<string> workflows whose scenarios Claude is asked to write
     */
    public array $briefs = [];

    /**
     * @var array<string, string> scenario => why it was left as it is
     */
    public array $kept = [];

    /**
     * @var list<string> workflows nothing could be written for without AI: a parameter, or no GET
     */
    public array $skipped = [];

    /**
     * @var list<string> scenarios recorded as reviewed
     */
    public array $reviewed = [];

    /**
     * @var array<string, string> file or scenario => what is wrong with it
     */
    public array $errors = [];
}
