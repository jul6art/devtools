<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Console;

use Jul6Art\DevTools\Command\ClaudeHookCommand;
use Jul6Art\DevTools\Command\ClaudeInstallCommand;
use Jul6Art\DevTools\Command\ClaudeUninstallCommand;
use Jul6Art\DevTools\Command\GitInstallHooksCommand;
use Jul6Art\DevTools\Command\GitUninstallHooksCommand;
use Jul6Art\DevTools\Command\InitCommand;
use Jul6Art\DevTools\Command\KnowledgeAmendCommand;
use Jul6Art\DevTools\Command\KnowledgeListCommand;
use Jul6Art\DevTools\Command\KnowledgePromoteCommand;
use Jul6Art\DevTools\Command\McpServeCommand;
use Jul6Art\DevTools\Command\ScenariosGenerateCommand;
use Jul6Art\DevTools\Command\StackDetectCommand;
use Jul6Art\DevTools\Command\WorkflowsAcceptCommand;
use Jul6Art\DevTools\Command\WorkflowsApplyCommand;
use Jul6Art\DevTools\Command\WorkflowsCheckCommand;
use Jul6Art\DevTools\Command\WorkflowsDiffCommand;
use Jul6Art\DevTools\Command\WorkflowsImpactCommand;
use Jul6Art\DevTools\Command\WorkflowsInspectCommand;
use Jul6Art\DevTools\Command\WorkflowsRejectCommand;
use Jul6Art\DevTools\Command\WorkflowsReviewCommand;
use Jul6Art\DevTools\Mcp\McpServer;
use Jul6Art\DevTools\Version;
use Symfony\Component\Console\Application as BaseApplication;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Exception\CommandNotFoundException;

/**
 * The standalone entry point: `vendor/bin/devtools`, usable on any project whatever its language.
 *
 * Every command lives in the core and is registered here. The Symfony bridge registers the same
 * commands in `bin/console` under the `devtools:` prefix, and adds nothing but kernel introspection:
 * a command that only works through the bridge is a command the standalone mode silently lacks.
 *
 * One command depends on a package DevTools only suggests: `mcp:serve`, registered when the MCP SDK is
 * installed, and otherwise answered with the line that installs it (ADR-0018).
 */
final class Application extends BaseApplication
{
    public const string NAME = 'DevTools';

    private readonly bool $mcp;

    /**
     * @param bool|null $mcp whether the MCP SDK is installed — null: look for it
     */
    public function __construct(?bool $mcp = null)
    {
        parent::__construct(self::NAME, Version::current());

        $this->mcp = $mcp ?? McpServer::available();

        $this->addCommands([
            new InitCommand(),
            new StackDetectCommand(),
            new WorkflowsInspectCommand(),
            new WorkflowsApplyCommand(),
            new WorkflowsDiffCommand(),
            new WorkflowsCheckCommand(),
            new WorkflowsImpactCommand(),
            new WorkflowsReviewCommand(),
            new WorkflowsAcceptCommand(),
            new WorkflowsRejectCommand(),
            new ScenariosGenerateCommand(),
            new GitInstallHooksCommand(),
            new GitUninstallHooksCommand(),
            new ClaudeInstallCommand(),
            new ClaudeUninstallCommand(),
            new ClaudeHookCommand(),
            new KnowledgeListCommand(),
            new KnowledgePromoteCommand(),
            new KnowledgeAmendCommand(),
        ]);

        if ($this->mcp) {
            $this->addCommands([new McpServeCommand()]);
        }
    }

    #[\Override]
    public function find(string $name): Command
    {
        try {
            return parent::find($name);
        } catch (CommandNotFoundException $unknown) {
            if ($this->mcp || McpServeCommand::NAME !== $name) {
                throw $unknown;
            }

            throw new CommandNotFoundException(\sprintf("%s\n\nThe MCP server needs the MCP SDK, which DevTools only suggests: composer require --dev %s", $unknown->getMessage(), McpServer::PACKAGE), $unknown->getAlternatives(), previous: $unknown);
        }
    }
}
