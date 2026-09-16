<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Inspection\Model;

final readonly class Transition
{
    public string $name;

    /**
     * @param list<string> $from
     * @param list<string> $to
     */
    public function __construct(string $name, public array $from, public array $to)
    {
        $this->name = NonEmpty::string($name, 'transition name');

        if ([] === $from || [] === $to) {
            throw new InvalidModel(\sprintf('The transition "%s" needs at least one place on each side.', $this->name));
        }
    }
}
