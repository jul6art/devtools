<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Ask;

use Jul6Art\DevTools\Ai\Api\AnthropicClient;
use Jul6Art\DevTools\Ai\Api\ApiFailed;
use Jul6Art\DevTools\Ai\Api\SecretScanner;
use Jul6Art\DevTools\Config\Config;
use Jul6Art\DevTools\Inspection\Model\WorkflowType;
use Jul6Art\DevTools\Inspection\Model\WorkflowTypeRegistry;
use Jul6Art\DevTools\Resources;
use Jul6Art\DevTools\Tracking\DevToolsDirectory;

/**
 * `devtools ask "<question>"` out of a Claude Code session (ADR-0039): one request to the Claude API, with the
 * selected pages as its only context — through the client of ADR-0035, so only where a project turned it on.
 *
 * The answer is never taken on trust: a workflow it cites that was not among its sources, a source that is
 * stale, are said with it.
 */
final readonly class QuestionAnswerer
{
    public const string PROMPT = 'prompts/ask/v1.md';

    public function __construct(private AnthropicClient $client = new AnthropicClient())
    {
    }

    public function hasKey(): bool
    {
        return $this->client->hasKey();
    }

    /**
     * @param list<SelectedPage> $pages
     *
     * @throws ApiFailed when the API refuses, or a page cannot leave the machine
     */
    public function answer(DevToolsDirectory $directory, Config $config, WorkflowTypeRegistry $types, string $question, array $pages): Answer
    {
        $scanner = new SecretScanner($config->excludes());
        $context = '';

        foreach ($pages as $page) {
            $file = $directory->root->absolute($page->page);
            $content = is_file($file) ? (string) file_get_contents($file) : '';
            $refusal = $scanner->refusal($page->page, $content);

            if (null !== $refusal) {
                throw new ApiFailed($refusal.': nothing was sent.');
            }

            $context .= \sprintf("<page id=\"%s\" title=\"%s\" updated=\"%s\" status=\"%s\">\n%s\n</page>\n", $page->id, htmlspecialchars($page->title, \ENT_QUOTES | \ENT_XML1), $page->updated, $page->status, trim($content));
        }

        $message = $this->client->messages([
            'model' => $config->aiModel,
            'max_tokens' => min($config->aiMaxTokens, 4000),
            'output_config' => ['effort' => 'low'],
            'system' => [['type' => 'text', 'text' => (string) file_get_contents(Resources::path(self::PROMPT))]],
            'messages' => [['role' => 'user', 'content' => '<question>'.htmlspecialchars($question, \ENT_QUOTES | \ENT_XML1)."</question>\n".$context]],
        ]);

        $usage = \is_array($message['usage'] ?? null) ? $message['usage'] : [];
        $count = static fn (string $key): int => \is_int($usage[$key] ?? null) ? $usage[$key] : 0;
        $tokens = ['input' => $count('input_tokens'), 'output' => $count('output_tokens'), 'cacheRead' => $count('cache_read_input_tokens'), 'cacheWrite' => $count('cache_creation_input_tokens')];

        if ('refusal' === ($message['stop_reason'] ?? null)) {
            return new Answer('', $pages, ['Claude declined to answer this question.'], $tokens);
        }

        $text = '';

        foreach (\is_array($message['content'] ?? null) ? $message['content'] : [] as $block) {
            if (\is_array($block) && 'text' === ($block['type'] ?? null) && \is_string($block['text'] ?? null)) {
                $text .= $block['text'];
            }
        }

        $warnings = 'max_tokens' === ($message['stop_reason'] ?? null) ? ['The answer was cut short.'] : [];

        return new Answer(trim($text), $pages, [...$warnings, ...self::warnings($text, $pages, $types)], $tokens);
    }

    /**
     * A source that is stale; a workflow cited that was not among the sources — which nothing then checks.
     *
     * @param list<SelectedPage> $pages
     *
     * @return list<string>
     */
    public static function warnings(string $text, array $pages, WorkflowTypeRegistry $types): array
    {
        $warnings = [];
        $given = array_map(static fn (SelectedPage $page): string => $page->id, $pages);
        $prefixes = implode('|', array_map(static fn (WorkflowType $type): string => preg_quote($type->idPrefix, '/'), $types->all()));

        preg_match_all('/(?<![\w.-])((?:'.$prefixes.')(?:\.[a-z0-9]+(?:-[a-z0-9]+)*)+)/', $text, $cited);

        foreach (array_values(array_unique($cited[1])) as $id) {
            if (!\in_array($id, $given, true)) {
                $warnings[] = \sprintf('The answer cites %s, which was not among its sources: nothing checks what it says of it.', $id);
            }
        }

        foreach ($pages as $page) {
            if ($page->isStale() && str_contains($text, $page->id)) {
                $warnings[] = \sprintf('%s is %s: its page may describe the code as it was (revised %s).', $page->id, $page->status, $page->updated);
            }
        }

        return $warnings;
    }
}
