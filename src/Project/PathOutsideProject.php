<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Project;

final class PathOutsideProject extends \RuntimeException
{
    public static function for(string $path, string $root): self
    {
        return new self(\sprintf('The path "%s" resolves outside the project root "%s"; DevTools never reads or writes there.', $path, $root));
    }
}
