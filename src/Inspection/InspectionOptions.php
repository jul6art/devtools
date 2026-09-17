<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Inspection;

use Jul6Art\DevTools\Project\ProjectRoot;
use Jul6Art\DevTools\Tracking\DevToolsDirectory;

final readonly class InspectionOptions
{
    /**
     * @param string|null  $only             a workflow type: only its pages and tracking files are written
     * @param list<string> $force            identifiers rewritten whatever their freshness
     * @param string|null  $since            compare with this commit, and hash every file
     * @param bool         $prune            delete the page and tracking file of orphaned workflows
     * @param bool         $noAi             write no brief for Claude: factual pages only
     * @param string|null  $language         the language the pages are written in, from `--locale`: it wins over the project's configuration
     * @param string|null  $fallbackLanguage the language to use when neither the option nor the project says (the Symfony bundle's configuration)
     * @param string|null  $docs             the directory the pages and the menu are written in, relative to the project; null for docs/workflows
     */
    public function __construct(
        public string $path,
        public ?string $only = null,
        public bool $dryRun = false,
        public array $force = [],
        public bool $forceAll = false,
        public ?string $since = null,
        public bool $prune = false,
        public bool $noAi = false,
        public ?string $language = null,
        public ?string $fallbackLanguage = null,
        public ?string $docs = null,
    ) {
        // The documentation directory is checked here rather than at the first write: a bad path must stop
        // the run before it has created anything.
        if (null !== $docs) {
            new DevToolsDirectory(new ProjectRoot($path), $docs);
        }

        foreach (['--locale' => $language, 'the configured language' => $fallbackLanguage] as $origin => $value) {
            if (null !== $value && 1 !== preg_match('/^[a-z]{2}$/', $value)) {
                throw new \InvalidArgumentException(\sprintf('"%s" is not a language: %s takes two lowercase letters, such as "en" or "fr".', $value, $origin));
            }
        }

        // The value ends up in `git diff <since>..HEAD`: one starting with a dash would be read as an
        // option of git (`--output=…` writes a file), not as a commit.
        if (null !== $since && (str_starts_with($since, '-') || '' === trim($since))) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not a commit: --since takes a commit, a tag or a branch.', $since));
        }
    }
}
