<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Scenario;

/**
 * One step of a scenario: an action of the closed vocabulary of `scenario.xsd` and its attributes (ADR-0020).
 */
final readonly class Step
{
    /**
     * The vocabulary, in the order of the schema: action => its attributes, in the order they are written.
     * A runner (ADR-0021) implements every one of them, or the action does not exist.
     */
    public const array ACTIONS = [
        'goto' => ['route'],
        'fill' => ['field', 'value'],
        'select' => ['field', 'value'],
        'check' => ['field'],
        'click' => ['text', 'selector'],
        'submit' => ['selector'],
        'wait-for' => ['selector', 'timeout'],
        'assert-route' => ['name'],
        'assert-visible' => ['selector'],
        'assert-text' => ['text', 'selector'],
        'assert-count' => ['selector', 'count'],
        'screenshot' => ['name'],
        'select2' => ['field', 'search', 'pick'],
    ];

    /**
     * @var array<string, string>
     */
    public array $arguments;

    /**
     * @param array<string, string> $arguments  the attributes of the action
     * @param array<string, string> $parameters the route parameters of a `goto`
     */
    public function __construct(public string $action, array $arguments = [], public array $parameters = [])
    {
        $known = self::ACTIONS[$action] ?? throw new \InvalidArgumentException(\sprintf('"%s" is not an action of the scenario vocabulary.', $action));
        $unknown = array_diff(array_keys($arguments), $known);

        if ([] !== $unknown) {
            throw new \InvalidArgumentException(\sprintf('The action "%s" takes no "%s".', $action, implode('", "', $unknown)));
        }

        if ('click' === $action && 1 !== \count($arguments)) {
            throw new \InvalidArgumentException('A click names its target once: a text or a selector.');
        }

        if ([] !== $parameters && 'goto' !== $action) {
            throw new \InvalidArgumentException('Only a goto takes route parameters.');
        }

        // The order of the vocabulary, whatever the order given: a scenario written twice is the same bytes.
        $this->arguments = array_filter(array_merge(array_fill_keys($known, null), $arguments), \is_string(...));
    }

    /**
     * The route this step goes to or asserts, or null.
     */
    public function route(): ?string
    {
        return match ($this->action) {
            'goto' => $this->arguments['route'] ?? null,
            'assert-route' => $this->arguments['name'] ?? null,
            default => null,
        };
    }
}
