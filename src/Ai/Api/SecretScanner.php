<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Ai\Api;

use Jul6Art\DevTools\Resources;
use Jul6Art\DevTools\Tracking\DevToolsDirectory;
use Jul6Art\DevTools\Xml\DomBuilder;
use Jul6Art\DevTools\Xml\SafeXmlLoader;

/**
 * What must never leave the machine (ADR-0035, SECURITY.md): a file the configuration excludes, an `.env*`
 * file, a content matching one of the patterns of `resources/secrets/patterns.xml`. Checked again on every
 * file of a task right before it would be sent — whatever wrote the brief.
 */
final readonly class SecretScanner
{
    /**
     * @var array<string, string> name => pattern
     */
    private array $patterns;

    /**
     * @param list<string> $excludes the directories the configuration excludes, relative to the project
     */
    public function __construct(private array $excludes)
    {
        $file = Resources::path('secrets/patterns.xml');
        $document = new SafeXmlLoader()->loadValidated((string) file_get_contents($file), $file, Resources::path('schemas/secret-patterns.xsd'), 1);
        $dom = new DomBuilder('https://github.com/jul6art/devtools/schema/secret-patterns/1');
        $patterns = [];

        foreach ($dom->children(DomBuilder::root($document), 'pattern') as $pattern) {
            $patterns[$pattern->getAttribute('name')] = '~'.str_replace('~', '\~', trim($pattern->textContent)).'~';
        }

        $this->patterns = $patterns;
    }

    /**
     * Why a file cannot be sent, or null when it can.
     */
    public function refusal(string $path, string $content): ?string
    {
        if (str_starts_with(basename($path), '.env')) {
            return \sprintf('%s is an environment file', $path);
        }

        // DevTools' own files — a model, a knowledge sheet — are what a brief is made of; their content is
        // still searched for secrets below.
        foreach (str_starts_with($path, DevToolsDirectory::NAME.'/') ? [] : $this->excludes as $excluded) {
            if ($path === $excluded || str_starts_with($path, rtrim($excluded, '/').'/') || \in_array($excluded, explode('/', $path), true)) {
                return \sprintf('%s is under %s, which the configuration excludes', $path, $excluded);
            }
        }

        foreach ($this->patterns as $name => $pattern) {
            if (1 === preg_match($pattern, $content)) {
                return \sprintf('%s holds what looks like a secret (%s)', $path, $name);
            }
        }

        return null;
    }
}
