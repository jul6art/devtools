<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Tests\Rule;

use Jul6Art\DevTools\Rule\Glob;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Glob::class)]
final class GlobTest extends TestCase
{
    #[DataProvider('globs')]
    public function testAGlobMatchesWhatItSays(string $glob, string $path, bool $matches): void
    {
        self::assertSame($matches, new Glob($glob)->matches($path));
    }

    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function globs(): iterable
    {
        yield 'a star stays in its directory' => ['src/*.php', 'src/Kernel.php', true];
        yield 'a star does not cross a slash' => ['src/*.php', 'src/Form/OrderType.php', false];
        yield 'two stars cross them' => ['src/**', 'src/Form/OrderType.php', true];
        yield 'two stars and a slash: any directory' => ['templates/**/_form.html.twig', 'templates/order/admin/_form.html.twig', true];
        yield 'two stars and a slash: no directory at all' => ['templates/**/_form.html.twig', 'templates/_form.html.twig', true];
        yield 'a question mark is one character' => ['src/?ernel.php', 'src/Kernel.php', true];
        yield 'a question mark is not a slash' => ['src?Kernel.php', 'src/Kernel.php', false];
        yield 'braces: one or the other' => ['templates/**/*.{twig,html}', 'templates/order/new.html.twig', true];
        yield 'braces: neither' => ['templates/**/*.{twig,html}', 'templates/order/new.css', false];
        yield 'a dot is a dot' => ['src/Kernel.php', 'src/Kernel_php', false];
        yield 'the whole path, not a part' => ['Form/*.php', 'src/Form/OrderType.php', false];
        yield 'an unclosed brace is a brace' => ['src/{Kernel.php', 'src/{Kernel.php', true];
    }

    public function testWhatIsWrittenOutMeasuresHowSpecificItIs(): void
    {
        self::assertSame(\strlen('templates/order/_form.html.twig'), new Glob('templates/order/_form.html.twig')->literal());
        self::assertSame(\strlen('templates//_form.html.twig'), new Glob('templates/**/_form.html.twig')->literal());
        self::assertSame(\strlen('templates/order/.'), new Glob('templates/order/?*.{twig,html}')->literal());
        self::assertSame(['src/Kernel.php'], new Glob('src/*.php')->filter(['src/Form/OrderType.php', 'src/Kernel.php']));
    }
}
