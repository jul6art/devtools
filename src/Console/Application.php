<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Console;

use Jul6Art\DevTools\Command\ClaudeInstallCommand;
use Jul6Art\DevTools\Command\GitInstallHooksCommand;
use Jul6Art\DevTools\Command\GitUninstallHooksCommand;
use Jul6Art\DevTools\Command\InitCommand;
use Jul6Art\DevTools\Command\KnowledgeListCommand;
use Jul6Art\DevTools\Command\KnowledgePromoteCommand;
use Jul6Art\DevTools\Command\StackDetectCommand;
use Jul6Art\DevTools\Command\WorkflowsAcceptCommand;
use Jul6Art\DevTools\Command\WorkflowsApplyCommand;
use Jul6Art\DevTools\Command\WorkflowsCheckCommand;
use Jul6Art\DevTools\Command\WorkflowsDiffCommand;
use Jul6Art\DevTools\Command\WorkflowsInspectCommand;
use Jul6Art\DevTools\Command\WorkflowsRejectCommand;
use Jul6Art\DevTools\Command\WorkflowsReviewCommand;
use Jul6Art\DevTools\Version;
use Symfony\Component\Console\Application as BaseApplication;

/**
 * The standalone entry point: `vendor/bin/devtools`, usable on any project whatever its language.
 *
 * Every command lives in the core and is registered here. The Symfony bridge registers the same
 * commands in `bin/console` under the `devtools:` prefix, and adds nothing but kernel introspection:
 * a command that only works through the bridge is a command the standalone mode silently lacks.
 */
final class Application extends BaseApplication
{
    public const string NAME = 'DevTools';

    public function __construct()
    {
        parent::__construct(self::NAME, Version::current());

        $this->addCommands([
            new InitCommand(),
            new StackDetectCommand(),
            new WorkflowsInspectCommand(),
            new WorkflowsApplyCommand(),
            new WorkflowsDiffCommand(),
            new WorkflowsCheckCommand(),
            new WorkflowsReviewCommand(),
            new WorkflowsAcceptCommand(),
            new WorkflowsRejectCommand(),
            new GitInstallHooksCommand(),
            new GitUninstallHooksCommand(),
            new ClaudeInstallCommand(),
            new KnowledgeListCommand(),
            new KnowledgePromoteCommand(),
        ]);
    }
}
