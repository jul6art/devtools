<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Tests\Inspection\Model;

use Jul6Art\DevTools\Inspection\Model\FileRef;
use Jul6Art\DevTools\Inspection\Model\FileRole;
use Jul6Art\DevTools\Inspection\Model\InvalidModel;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A FileRef is the only way a path enters the model. Refusing an absolute or escaping path here is
 * what guarantees that no page, tracking file or brief ever points outside the analysed project.
 */
#[CoversClass(FileRef::class)]
#[CoversClass(InvalidModel::class)]
final class FileRefTest extends TestCase
{
    #[DataProvider('normalisedPaths')]
    public function testItNormalisesThePath(string $given, string $expected): void
    {
        self::assertSame($expected, new FileRef($given)->path);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function normalisedPaths(): iterable
    {
        yield 'already normal' => ['src/Controller/OrderController.php', 'src/Controller/OrderController.php'];
        yield 'leading dot segment' => ['./src/Kernel.php', 'src/Kernel.php'];
        yield 'inner dot segment' => ['src/./Kernel.php', 'src/Kernel.php'];
        yield 'windows separators' => ['src\\Form\\OrderType.php', 'src/Form/OrderType.php'];
        yield 'doubled separators' => ['templates//order/new.html.twig', 'templates/order/new.html.twig'];
        yield 'surrounding spaces' => ['  config/routes.yaml ', 'config/routes.yaml'];
    }

    #[DataProvider('rejectedPaths')]
    public function testItRejectsAPathThatDoesNotBelongToTheProject(string $path, string $reason): void
    {
        $this->expectException(InvalidModel::class);
        $this->expectExceptionMessage($reason);

        new FileRef($path);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function rejectedPaths(): iterable
    {
        yield 'empty' => ['', 'empty'];
        yield 'only dots' => ['./.', 'empty'];
        yield 'absolute unix' => ['/etc/passwd', 'absolute'];
        yield 'absolute windows' => ['C:\\Windows\\win.ini', 'absolute'];
        yield 'parent segment' => ['src/../../secrets.env', 'outside the project root'];
        yield 'leading parent' => ['../other-project/src/Kernel.php', 'outside the project root'];
        yield 'vendor file' => ['vendor/symfony/form/Form.php', 'PackageRef'];
        yield 'nested node_modules file' => ['front/node_modules/lodash/index.js', 'PackageRef'];
    }

    public function testItDefaultsToTheOtherRole(): void
    {
        self::assertSame(FileRole::Other, new FileRef('README.md')->role);
    }

    public function testTwoRefsToTheSamePathAreEqualWhateverTheirSpelling(): void
    {
        self::assertTrue(new FileRef('./src/Kernel.php')->samePathAs(new FileRef('src\\Kernel.php', FileRole::Service)));
        self::assertFalse(new FileRef('src/Kernel.php')->samePathAs(new FileRef('src/Kernel2.php')));
    }
}
