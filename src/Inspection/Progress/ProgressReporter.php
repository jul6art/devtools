<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Inspection\Progress;

/**
 * What an inspection is doing, as it does it (ADR-0042).
 *
 * No Symfony type appears here on purpose: the pipeline runs under `bin/devtools`, under `bin/console`
 * and in tests that instantiate it without any input or output. The console implementation lives in
 * `Console\`; {@see NullProgressReporter} is what the pipeline gets when nobody is watching.
 *
 * Not `final`: a consumer — a CI runner, an editor plugin — doubles this seam to show progress its own
 * way, which is exactly why it is an interface and not a concrete class.
 */
interface ProgressReporter
{
    /**
     * Opens a stage. `$steps` is known only when the work is countable — the number of workflows to
     * render — and null otherwise.
     */
    public function stage(string $name, ?int $steps = null): void;

    /**
     * One step of the current stage, labelled with what it is working on.
     */
    public function advance(string $label): void;

    /**
     * Closes the current stage.
     */
    public function finish(): void;
}
