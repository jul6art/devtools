<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Ai\Api;

use Jul6Art\DevTools\Ai\ApplyResult;

/**
 * What one `ai:run` did and spent (ADR-0035): the briefs written, refused, skipped and kept, the tokens, and
 * what they probably cost.
 */
final class AiRunReport
{
    /**
     * @var list<string> the briefs whose draft was written
     */
    public array $drafted = [];

    /**
     * @var array<string, string> brief => why nothing was written
     */
    public array $refused = [];

    /**
     * @var array<string, string> brief => why it was not even sent
     */
    public array $skipped = [];

    /**
     * @var list<string> the briefs left for the next run, a limit reached
     */
    public array $kept = [];

    public ?string $stopped = null;

    public ApplyResult $applied;

    /**
     * @var array{input: int, output: int, cacheRead: int, cacheWrite: int}
     */
    public array $usage = ['input' => 0, 'output' => 0, 'cacheRead' => 0, 'cacheWrite' => 0];

    private int $requests = 0;

    private int $sent = 0;

    public function __construct(public readonly string $model)
    {
        $this->applied = new ApplyResult();
    }

    /**
     * @param array<mixed> $usage the `usage` of a message
     */
    public function count(array $usage): void
    {
        ++$this->requests;

        foreach (['input' => 'input_tokens', 'output' => 'output_tokens', 'cacheRead' => 'cache_read_input_tokens', 'cacheWrite' => 'cache_creation_input_tokens'] as $key => $field) {
            $this->usage[$key] += \is_int($usage[$field] ?? null) ? $usage[$field] : 0;
        }
    }

    /**
     * The briefs sent to the API, whatever came of them.
     */
    public function sent(): int
    {
        return $this->sent;
    }

    public function send(): void
    {
        ++$this->sent;
    }

    public function tokens(): int
    {
        return $this->usage['input'] + $this->usage['output'] + $this->usage['cacheRead'] + $this->usage['cacheWrite'];
    }

    public function requests(): int
    {
        return $this->requests;
    }

    public function cost(): ?float
    {
        return Pricing::estimate($this->model, $this->usage);
    }

    public function toMarkdown(\DateTimeImmutable $at): string
    {
        $lines = ['# ai:run — '.$at->format('Y-m-d H:i'), '', \sprintf('Model %s · %d requests · %d input tokens · %d output · %d read from the cache · %d written to it · %s', $this->model, $this->requests, $this->usage['input'], $this->usage['output'], $this->usage['cacheRead'], $this->usage['cacheWrite'], null === $this->cost() ? 'cost unknown' : \sprintf('about $%.2f', $this->cost())), ''];

        foreach (['Drafted' => array_fill_keys($this->drafted, ''), 'Accepted' => array_fill_keys($this->applied->accepted, ''), 'Refused by the validators' => array_map(static fn (array $errors): string => implode('; ', $errors), $this->applied->refused), 'Not written' => $this->refused, 'Not sent' => $this->skipped, 'Kept for the next run' => array_fill_keys($this->kept, '')] as $title => $entries) {
            if ([] === $entries) {
                continue;
            }

            $lines[] = '## '.$title;
            $lines[] = '';

            foreach ($entries as $name => $why) {
                $lines[] = '- `'.$name.'`'.('' === $why ? '' : ' — '.$why);
            }

            $lines[] = '';
        }

        if (null !== $this->stopped) {
            $lines[] = 'Stopped: '.$this->stopped.'.';
        }

        return implode("\n", $lines)."\n";
    }
}
