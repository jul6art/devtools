<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Tests\Inspection\Pipeline;

use Jul6Art\DevTools\Config\Config;
use Jul6Art\DevTools\Inspection\Adapter\AdapterInterface;
use Jul6Art\DevTools\Inspection\Adapter\AdapterResult;
use Jul6Art\DevTools\Inspection\Graph\EntryPointCandidate;
use Jul6Art\DevTools\Inspection\Graph\PhpReferenceExtractor;
use Jul6Art\DevTools\Project\ProjectRoot;
use Jul6Art\DevTools\Stack\StackProfile;
use Jul6Art\DevTools\Tests\Inspection\Graph\GraphFixture;

/**
 * Stands in for the Symfony adapter: the hand-written entry points of symfony-minimal, no console.
 */
final readonly class FakeAdapter implements AdapterInterface
{
    /**
     * @param list<string> $withoutEntryPoints names of entry points to leave out, as if deleted from the code
     */
    public function __construct(private array $withoutEntryPoints = [], private ?string $fallbackCause = null, private bool $findsNothing = false)
    {
    }

    public function name(): string
    {
        return 'symfony';
    }

    public function extract(ProjectRoot $root, StackProfile $stack, Config $config, PhpReferenceExtractor $extractor): AdapterResult
    {
        $candidates = $this->findsNothing ? [] : array_values(array_filter(GraphFixture::candidates(), fn (EntryPointCandidate $candidate): bool => !\in_array($candidate->entryPoint->name, $this->withoutEntryPoints, true)));

        return new AdapterResult($candidates, ['templates'], [], $this->fallbackCause);
    }
}
