<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Tests\Mcp;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * ADR-0018: the MCP SDK is pre-1.0 and only suggested — no class outside `src/Mcp/` names it, so that its
 * absence and its breaking changes stay in one directory.
 */
#[CoversNothing]
final class SdkBoundaryTest extends TestCase
{
    private const string SOURCES = __DIR__.'/../../src';

    public function testNoClassOutsideTheMcpDirectoryNamesTheSdk(): void
    {
        $offenders = [];
        $inside = 0;

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::SOURCES, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if (!$file instanceof \SplFileInfo || 'php' !== $file->getExtension()) {
                continue;
            }

            $names = self::sdkNames((string) file_get_contents($file->getPathname()));
            $relative = substr($file->getPathname(), \strlen(self::SOURCES) + 1);

            if (str_starts_with($relative, 'Mcp/')) {
                $inside += \count($names);
            } elseif ([] !== $names) {
                $offenders[] = $relative.': '.implode(', ', $names);
            }
        }

        self::assertSame([], $offenders);
        self::assertGreaterThan(0, $inside, 'The scan finds the SDK where it is named: src/Mcp/.');
    }

    /**
     * @return list<string> the names of the SDK's namespace the code mentions, imported or qualified
     */
    private static function sdkNames(string $code): array
    {
        $names = [];

        foreach (\PhpToken::tokenize($code) as $token) {
            if ($token->is([\T_NAME_QUALIFIED, \T_NAME_FULLY_QUALIFIED]) && 1 === preg_match('/^\\\\?Mcp\\\\/', $token->text)) {
                $names[] = $token->text;
            }
        }

        return $names;
    }
}
