<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Tests\Performance;

use Jul6Art\DevTools\Project\ProjectRoot;
use Jul6Art\DevTools\Rule\AppliesTo;
use Jul6Art\DevTools\Rule\Rule;
use Jul6Art\DevTools\Rule\RuleIndex;
use Jul6Art\DevTools\Rule\RuleIndexEntry;
use Jul6Art\DevTools\Rule\RuleStatus;
use Jul6Art\DevTools\Rule\XmlRuleIndexStore;
use Jul6Art\DevTools\Tests\Support\GeneratesLargeSymfonyProject;
use Jul6Art\DevTools\Tracking\DevToolsDirectory;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * ADR-0019: the PreToolUse hook runs before every edit, so under 300 ms end to end — PHP started, the map
 * read, the answer written — on a 300-route project, for a service every route traverses. And with forty
 * active rules to match, the five given counted (ADR-0026).
 */
#[CoversNothing]
#[Group('performance')]
final class HookPerformanceTest extends TestCase
{
    use GeneratesLargeSymfonyProject;

    public function testThePreToolUseHookAnswersInUnder300MillisecondsEndToEnd(): void
    {
        $project = $this->inspectedLargeProject();
        $rules = array_map(
            static fn (int $rule): RuleIndexEntry => RuleIndexEntry::of(new Rule('rule-'.$rule, RuleStatus::Active, '2026-10-20', ['fb-2026-10-20-feedback-'.$rule], new AppliesTo(0 === $rule % 2 ? ['src/Service/*.php', 'templates/**/*.twig'] : ['src/Controller/**'], 0 === $rule % 4 ? ['routes'] : []), 'Rule number '.$rule.' says one thing.')),
            range(1, 40),
        );
        new XmlRuleIndexStore()->write(DevToolsDirectory::documented(new ProjectRoot($project))->rulesDirectory().'/index.xml', new RuleIndex($rules));
        $input = (string) json_encode(['hook_event_name' => 'PreToolUse', 'tool_name' => 'Edit', 'tool_input' => ['file_path' => $project.'/src/Service/Catalog.php']]);
        $command = [\PHP_BINARY, \dirname(__DIR__, 2).'/bin/devtools', 'claude:hook', 'PreToolUse', '--path='.$project];

        // A first run warms the file system cache, as a real session's first edit has long done.
        new Process($command, input: $input)->mustRun();

        $started = microtime(true);
        $process = new Process($command, input: $input);
        $process->mustRun();
        $milliseconds = 1000 * (microtime(true) - $started);

        self::assertStringContainsString('is part of 300 documented workflows', $process->getOutput());
        self::assertSame(5, substr_count($process->getOutput(), '- rule-'), 'Five rules given, out of the twenty that apply.');
        self::assertLessThan(300.0, $milliseconds, \sprintf('The hook took %.0f ms.', $milliseconds));
        fwrite(\STDERR, \sprintf("\n[performance] PreToolUse hook on a service shared by %d routes, end to end: %.0f ms\n", self::ROUTES, $milliseconds));
    }
}
