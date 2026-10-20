<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Claude\Hook;

use Jul6Art\DevTools\Tracking\DevToolsDirectory;

/**
 * One event of Claude Code, answered by DevTools (ADR-0019). Later lots add a handler, never a command.
 */
interface HookHandlerInterface
{
    public function event(): string;

    /**
     * @return array<string, mixed>|null the JSON answer, or null to say nothing
     */
    public function handle(HookInput $input, DevToolsDirectory $directory): ?array;
}
