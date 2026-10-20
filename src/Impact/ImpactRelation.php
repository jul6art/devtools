<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Impact;

use Jul6Art\DevTools\Tracking\FileRelation;

/**
 * How a changed file reaches a workflow (ADR-0016), strongest first.
 */
enum ImpactRelation: string
{
    /** The workflow traverses the file. */
    case Direct = 'direct';

    /** The file is a test of the workflow. */
    case Test = 'test';

    /** The file runs inside the workflow without being one of its own: a mechanism, a workflow it depends on. */
    case Dependency = 'dependency';

    public static function of(FileRelation $relation): self
    {
        return match ($relation) {
            FileRelation::File => self::Direct,
            FileRelation::Test => self::Test,
            FileRelation::Dependency => self::Dependency,
        };
    }

    public function isStrongerThan(self $other): bool
    {
        return array_search($this, self::cases(), true) < array_search($other, self::cases(), true);
    }
}
