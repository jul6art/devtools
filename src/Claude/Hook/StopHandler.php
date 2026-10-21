<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Claude\Hook;

use Jul6Art\DevTools\Gate\Gate;
use Jul6Art\DevTools\Gate\GateRenderer;
use Jul6Art\DevTools\Gate\GateResult;
use Jul6Art\DevTools\Gate\XmlGateStore;
use Jul6Art\DevTools\Inspection\Freshness\GitClient;
use Jul6Art\DevTools\Tracking\DevToolsDirectory;
use Jul6Art\DevTools\Verification\VerificationPreparer;

/**
 * When Claude is about to end a task (ADR-0023): the gate on the working tree. While it fails, the task is not
 * finished — the hook blocks, and the gate's text is the reason Claude reads. Nothing changed since it last
 * passed: it does not run again. The same failure three times: Claude is asked to stop trying and to tell the
 * human; a fourth, it may stop.
 */
final readonly class StopHandler implements HookHandlerInterface
{
    public function __construct(private Gate $gate = new Gate(), private GitClient $git = new GitClient(), private XmlGateStore $store = new XmlGateStore())
    {
    }

    #[\Override]
    public function event(): string
    {
        return 'Stop';
    }

    #[\Override]
    public function handle(HookInput $input, DevToolsDirectory $directory): ?array
    {
        if (!is_file($directory->indexFile())) {
            return null;
        }

        $changed = Gate::changes($directory, $this->git->workingTreeChanges($directory->root) ?? []);

        if ([] === $changed) {
            return null;
        }

        // Passed on this very state of the change: nothing to run again.
        $last = $this->store->read($directory->path('gate/last.xml'));

        if ($last instanceof GateResult && $last->passed() && $last->changes === VerificationPreparer::fingerprint($directory->root, $changed)) {
            return null;
        }

        $result = $this->gate->run($directory, $changed);

        if ($result->passed()) {
            return null;
        }

        $text = new GateRenderer()->text($result);

        return match (true) {
            $result->consecutive > Gate::LIMIT => ['systemMessage' => \sprintf("The DevTools gate still fails, the same way, after %d attempts: it lets the task end.\n\n%s", Gate::LIMIT, $text)],
            Gate::LIMIT === $result->consecutive => ['decision' => 'block', 'reason' => \sprintf("The DevTools gate failed %d times the same way. Stop trying to fix it: end with a message that tells the human what still fails, and why.\n\n%s", Gate::LIMIT, $text)],
            default => ['decision' => 'block', 'reason' => "The DevTools gate fails: the task is not finished. Fix what follows, then end again.\n\n".$text],
        };
    }
}
