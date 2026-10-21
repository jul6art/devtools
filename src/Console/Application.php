<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Console;

use Jul6Art\DevTools\Command\AssertionsProposeCommand;
use Jul6Art\DevTools\Command\BaselinesAcceptCommand;
use Jul6Art\DevTools\Command\ClaudeHookCommand;
use Jul6Art\DevTools\Command\ClaudeInstallCommand;
use Jul6Art\DevTools\Command\ClaudeUninstallCommand;
use Jul6Art\DevTools\Command\FeedbackAddCommand;
use Jul6Art\DevTools\Command\FeedbackListCommand;
use Jul6Art\DevTools\Command\FeedbackResolveCommand;
use Jul6Art\DevTools\Command\GateCommand;
use Jul6Art\DevTools\Command\GitInstallHooksCommand;
use Jul6Art\DevTools\Command\GitUninstallHooksCommand;
use Jul6Art\DevTools\Command\InitCommand;
use Jul6Art\DevTools\Command\KnowledgeAmendCommand;
use Jul6Art\DevTools\Command\KnowledgeListCommand;
use Jul6Art\DevTools\Command\KnowledgePromoteCommand;
use Jul6Art\DevTools\Command\McpServeCommand;
use Jul6Art\DevTools\Command\RulesActivateCommand;
use Jul6Art\DevTools\Command\RulesListCommand;
use Jul6Art\DevTools\Command\RulesPromoteCommand;
use Jul6Art\DevTools\Command\ScenariosGenerateCommand;
use Jul6Art\DevTools\Command\ScenariosRunCommand;
use Jul6Art\DevTools\Command\StackDetectCommand;
use Jul6Art\DevTools\Command\VerifyCollectCommand;
use Jul6Art\DevTools\Command\VerifyPrepareCommand;
use Jul6Art\DevTools\Command\WorkflowsAcceptCommand;
use Jul6Art\DevTools\Command\WorkflowsApplyCommand;
use Jul6Art\DevTools\Command\WorkflowsCheckCommand;
use Jul6Art\DevTools\Command\WorkflowsDiffCommand;
use Jul6Art\DevTools\Command\WorkflowsImpactCommand;
use Jul6Art\DevTools\Command\WorkflowsInspectCommand;
use Jul6Art\DevTools\Command\WorkflowsRejectCommand;
use Jul6Art\DevTools\Command\WorkflowsReviewCommand;
use Jul6Art\DevTools\Mcp\McpServer;
use Jul6Art\DevTools\Scenario\Runner\PantherRunner;
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
 * Two commands depend on a package DevTools only suggests — `mcp:serve` on the MCP SDK (ADR-0018),
 * `scenarios:run` on Panther (ADR-0021): each is registered when its package is installed, and otherwise
 * answered with the line that installs it.
 */
final class Application extends BaseApplication
{
    public const string NAME = 'DevTools';

    /**
     * The commands of a package DevTools only suggests: name => whether it is installed, what installs it,
     * and what needs it.
     *
     * @var array<string, array{bool, string, string}>
     */
    private readonly array $optional;

    /**
     * @param bool|null $mcp     whether the MCP SDK is installed — null: look for it
     * @param bool|null $panther whether Panther is installed — null: look for it
     */
    public function __construct(?bool $mcp = null, ?bool $panther = null)
    {
        parent::__construct(self::NAME, Version::current());

        $this->optional = [
            McpServeCommand::NAME => [$mcp ?? McpServer::available(), McpServer::PACKAGE, 'The MCP server needs the MCP SDK'],
            ScenariosRunCommand::NAME => [$panther ?? PantherRunner::available(), PantherRunner::PACKAGE, 'Replaying the scenarios needs Panther'],
        ];

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
            new BaselinesAcceptCommand(),
            new VerifyPrepareCommand(),
            new VerifyCollectCommand(),
            new GateCommand(),
            new FeedbackAddCommand(),
            new FeedbackListCommand(),
            new FeedbackResolveCommand(),
            new RulesPromoteCommand(),
            new RulesActivateCommand(),
            new RulesListCommand(),
            new AssertionsProposeCommand(),
            new GitInstallHooksCommand(),
            new GitUninstallHooksCommand(),
            new ClaudeInstallCommand(),
            new ClaudeUninstallCommand(),
            new ClaudeHookCommand(),
            new KnowledgeListCommand(),
            new KnowledgePromoteCommand(),
            new KnowledgeAmendCommand(),
        ]);

        if ($this->optional[McpServeCommand::NAME][0]) {
            $this->addCommands([new McpServeCommand()]);
        }

        if ($this->optional[ScenariosRunCommand::NAME][0]) {
            $this->addCommands([new ScenariosRunCommand()]);
        }
    }

    #[\Override]
    public function find(string $name): Command
    {
        try {
            return parent::find($name);
        } catch (CommandNotFoundException $unknown) {
            [$installed, $package, $need] = $this->optional[$name] ?? [true, '', ''];

            if ($installed) {
                throw $unknown;
            }

            throw new CommandNotFoundException(\sprintf("%s\n\n%s, which DevTools only suggests: composer require --dev %s", $unknown->getMessage(), $need, $package), $unknown->getAlternatives(), previous: $unknown);
        }
    }
}
