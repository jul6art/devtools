<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Inspection;

use Jul6Art\DevTools\Inspection\Adapter\AdapterInterface;
use Jul6Art\DevTools\Inspection\Adapter\Claude\ClaudeDrivenAdapter;
use Jul6Art\DevTools\Inspection\Adapter\GenericPhp\GenericPhpAdapter;
use Jul6Art\DevTools\Inspection\Adapter\Symfony\SymfonyAdapter;

/**
 * The adapter `stack.xml` names for a stack.
 */
final readonly class AdapterResolver
{
    /**
     * @var array<string, AdapterInterface>
     */
    private array $adapters;

    /**
     * @param list<AdapterInterface>|null $adapters
     */
    public function __construct(?array $adapters = null)
    {
        $byName = [];

        foreach ($adapters ?? [new SymfonyAdapter(), new GenericPhpAdapter(), new ClaudeDrivenAdapter()] as $adapter) {
            $byName[$adapter->name()] = $adapter;
        }

        $this->adapters = $byName;
    }

    public function for(string $name): ?AdapterInterface
    {
        return $this->adapters[$name] ?? null;
    }
}
