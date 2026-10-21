<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Assertion;

/**
 * A CSS selector on the DOM the screenshots of a scenario captured (ADR-0021, ADR-0027): no browser is started,
 * the captures are read as they were written.
 */
final readonly class DomCheck
{
    /**
     * @param string      $scenario a scenario — `route.order.new/happy-path` — or a workflow: all its scenarios
     * @param string|null $capture  one screenshot, by name; null: any of them
     */
    public function __construct(public string $selector, public Expect $expect, public string $scenario, public ?string $capture = null)
    {
        if (!\in_array($expect, [Expect::MustExist, Expect::MustNotExist], true)) {
            throw new \InvalidArgumentException('An element must exist, or must not.');
        }
    }

    public function covers(string $scenario, string $capture): bool
    {
        return ($scenario === $this->scenario || str_starts_with($scenario, $this->scenario.'/')) && (null === $this->capture || $capture === $this->capture);
    }
}
