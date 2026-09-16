<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Stack;

/**
 * One stack of the analysed project, as `stack.xml` describes it (ADR-0005).
 *
 * Directories are relative to the stack's root, which is itself relative to the project (`.` for the
 * project root, `front` in a monorepo). `locked` lists the elements a human pinned in `stack.xml`:
 * detection never changes them.
 */
final readonly class StackProfile
{
    public const array LOCKABLE = ['language', 'framework', 'package-manager', 'adapter', 'knowledge', 'sources', 'tests', 'excludes'];

    /**
     * @var list<string>
     */
    public array $sourceDirs;

    /**
     * @var list<string>
     */
    public array $testDirs;

    /**
     * @var list<string>
     */
    public array $excludedDirs;

    /**
     * @var list<string>
     */
    public array $locked;

    /**
     * @param list<string> $sourceDirs
     * @param list<string> $testDirs
     * @param list<string> $excludedDirs
     * @param list<string> $locked
     */
    public function __construct(
        public string $root,
        public string $language,
        public ?string $framework,
        public ?string $version,
        public string $packageManager,
        array $sourceDirs,
        array $testDirs,
        array $excludedDirs,
        public string $adapter,
        public ?string $knowledgeKey,
        array $locked = [],
    ) {
        if ('' === $root || str_starts_with($root, '/') || \in_array('..', explode('/', $root), true)) {
            throw new \InvalidArgumentException(\sprintf('The stack root "%s" must be a path inside the project ("." for its root).', $root));
        }

        if (null === $framework && null !== $version) {
            throw new \InvalidArgumentException('A version is the version of a framework.');
        }

        foreach ($locked as $element) {
            if (!\in_array($element, self::LOCKABLE, true)) {
                throw new \InvalidArgumentException(\sprintf('"%s" cannot be locked in stack.xml.', $element));
            }
        }

        $this->sourceDirs = self::sorted($sourceDirs);
        $this->testDirs = self::sorted($testDirs);
        $this->excludedDirs = self::sorted($excludedDirs);
        $this->locked = self::sorted($locked);
    }

    public function isLocked(string $element): bool
    {
        return \in_array($element, $this->locked, true);
    }

    /**
     * Keeps from `$previous` every element it had locked, and the locks themselves.
     */
    public function withLocksFrom(self $previous): self
    {
        $keep = $previous->isLocked(...);

        return new self(
            $this->root,
            $keep('language') ? $previous->language : $this->language,
            $keep('framework') ? $previous->framework : $this->framework,
            $keep('framework') ? $previous->version : $this->version,
            $keep('package-manager') ? $previous->packageManager : $this->packageManager,
            $keep('sources') ? $previous->sourceDirs : $this->sourceDirs,
            $keep('tests') ? $previous->testDirs : $this->testDirs,
            $keep('excludes') ? $previous->excludedDirs : $this->excludedDirs,
            $keep('adapter') ? $previous->adapter : $this->adapter,
            $keep('knowledge') ? $previous->knowledgeKey : $this->knowledgeKey,
            $previous->locked,
        );
    }

    /**
     * A directory of this stack, relative to the project root.
     */
    public function projectPath(string $directory): string
    {
        $path = '.' === $this->root ? $directory : $this->root.'/'.$directory;

        return '.' === $directory ? $this->root : $path;
    }

    /**
     * @param list<string> $values
     *
     * @return list<string>
     */
    private static function sorted(array $values): array
    {
        $values = array_values(array_unique($values));
        sort($values, \SORT_STRING);

        return $values;
    }
}
