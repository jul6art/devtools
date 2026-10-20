<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Tests\Scenario;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * ADR-0021: Panther is only suggested — the browser is named where scenarios are replayed, and nowhere else, so
 * that a project without it generates its scenarios and runs everything but `scenarios:run`.
 */
#[CoversNothing]
final class PantherBoundaryTest extends TestCase
{
    private const string SOURCES = __DIR__.'/../../src';

    /**
     * Where the browser may be named.
     */
    private const array ALLOWED = ['Scenario/Action/', 'Scenario/Runner/', 'Command/ScenariosRunCommand.php', 'Bridge/Symfony/Test/'];

    public function testOnlyTheRunnerNamesTheBrowser(): void
    {
        $offenders = [];
        $inside = 0;

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::SOURCES, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if (!$file instanceof \SplFileInfo || 'php' !== $file->getExtension()) {
                continue;
            }

            $relative = substr($file->getPathname(), \strlen(self::SOURCES) + 1);
            $names = self::browserNames((string) file_get_contents($file->getPathname()));

            if (array_any(self::ALLOWED, static fn (string $allowed): bool => str_starts_with($relative, $allowed))) {
                $inside += \count($names);
            } elseif ([] !== $names) {
                $offenders[] = $relative.': '.implode(', ', $names);
            }
        }

        self::assertSame([], $offenders);
        self::assertGreaterThan(0, $inside, 'The scan finds the browser where it is named.');
    }

    /**
     * @return list<string>
     */
    private static function browserNames(string $code): array
    {
        $names = [];

        foreach (\PhpToken::tokenize($code) as $token) {
            if ($token->is([\T_NAME_QUALIFIED, \T_NAME_FULLY_QUALIFIED]) && 1 === preg_match('/^\\\\?(Symfony\\\\Component\\\\Panther|Facebook\\\\WebDriver)\\\\/', $token->text)) {
                $names[] = $token->text;
            }
        }

        return $names;
    }
}
