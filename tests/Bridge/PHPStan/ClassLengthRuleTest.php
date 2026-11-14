<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Tests\Bridge\PHPStan;

use Jul6Art\DevTools\Bridge\PHPStan\ClassLength;
use Jul6Art\DevTools\Bridge\PHPStan\ClassLengthRule;
use Jul6Art\DevTools\Tests\Support\UsesTemporaryDirectory;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\Process\Process;

/**
 * @extends RuleTestCase<ClassLengthRule>
 */
#[CoversClass(ClassLengthRule::class)]
final class ClassLengthRuleTest extends RuleTestCase
{
    use UsesTemporaryDirectory;

    private const int MAX = 20;

    public function testALongClassIsReportedAtItsLineWithItsCount(): void
    {
        // The declaration: a 3-line docblock, the class line, the opening brace, 15 lines of body, the closing brace —
        // 21 lines, from line 6 to line 26.
        $file = $this->write('src/Service/Long.php', self::declaration('App\Service', 'Long', docblock: 3, body: 15));

        $this->analyse([$file], [
            ['Class App\Service\Long is longer than 20 lines.', 9, '21 lines from its docblock to its closing brace: split what it does into classes of their own.'],
        ]);
    }

    /**
     * The file's header — namespace, imports — says nothing of what the class does, and does not count: thirty
     * imports above a class of exactly the threshold produce nothing.
     */
    public function testTheHeaderOfTheFileDoesNotCount(): void
    {
        $file = $this->write('src/Service/Imports.php', self::declaration('App\Service', 'Imports', docblock: 3, body: 14, imports: 30));

        $this->analyse([$file], []);
    }

    public function testAnExcludedFileIsNotMeasured(): void
    {
        $file = $this->write('src/Entity/Order.php', self::declaration('App\Entity', 'Order', docblock: 0, body: 40));

        $this->analyse([$file], []);
    }

    public function testAnAnonymousClassIsNotMeasuredItself(): void
    {
        $body = "    public function make(): object\n    {\n        return new class {\n".str_repeat("            public int \$value = 0;\n", 30)."        };\n    }\n";
        $file = $this->write('src/Service/Factory.php', "<?php\n\nnamespace App\\Service;\n\nfinal class Factory\n{\n".$body."}\n");

        // The factory holds the anonymous class, so the factory is the long one — reported once, not twice.
        $this->analyse([$file], [
            ['Class App\Service\Factory is longer than 20 lines.', 5, '38 lines from its docblock to its closing brace: split what it does into classes of their own.'],
        ]);
    }

    /**
     * The rule as a project takes it: the neon included alone, its threshold and exclusions set, and a real PHPStan
     * that reports the class under the rule's identifier.
     *
     * ⚠️ Its own `tmpDir`: two PHPStan runs sharing the system's result cache can corrupt it, and the next one fails
     * on "Malformed entry header" with nothing wrong in the code.
     */
    public function testTheNeonIsIncludedAloneAndItsSettingsApply(): void
    {
        $this->write('src/Service/Long.php', self::declaration('App\Service', 'Long', docblock: 0, body: 12));
        $this->write('src/Legacy/Old.php', self::declaration('App\Legacy', 'Old', docblock: 0, body: 40));
        $config = $this->write('phpstan.neon', "includes:\n    - ".\dirname(__DIR__, 3)."/resources/phpstan/class-length.neon\n\nparameters:\n    level: 0\n    tmpDir: ".$this->temporaryDirectory()."/phpstan\n    paths:\n        - src\n    devtoolsClassLength:\n        max: 10\n        exclude!:\n            - '*/Legacy/*'\n");

        $process = new Process([\PHP_BINARY, \dirname(__DIR__, 3).'/vendor/bin/phpstan', 'analyse', '--no-progress', '--error-format=json', '-c', $config, '--autoload-file', \dirname(__DIR__, 3).'/vendor/autoload.php'], $this->temporaryDirectory());
        $process->run();

        $report = json_decode($process->getOutput(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($report, $process->getErrorOutput());
        $totals = $report['totals'] ?? null;
        self::assertIsArray($totals);
        self::assertSame(1, $totals['file_errors'] ?? null, $process->getOutput());

        $files = $report['files'] ?? null;
        self::assertIsArray($files);
        $long = $files[$this->temporaryDirectory().'/src/Service/Long.php'] ?? null;
        self::assertIsArray($long, 'The long class is the one reported; the excluded one is not.');
        $messages = $long['messages'] ?? null;
        self::assertIsArray($messages);
        $first = $messages[0] ?? null;
        self::assertIsArray($first);
        self::assertSame('Class App\Service\Long is longer than 10 lines.', $first['message'] ?? null);
        self::assertSame('devtools.classLength', $first['identifier'] ?? null);
    }

    #[\Override]
    protected function getRule(): Rule
    {
        return new ClassLengthRule(new ClassLength(self::MAX));
    }

    private function write(string $path, string $contents): string
    {
        $file = $this->temporaryDirectory().'/'.$path;

        if (!is_dir(\dirname($file))) {
            mkdir(\dirname($file), 0o777, true);
        }

        file_put_contents($file, $contents);

        return $file;
    }

    /**
     * A file: its header (namespace, imports), then the declaration — a docblock, the class line, a body of
     * properties, the closing brace.
     */
    private static function declaration(string $namespace, string $class, int $docblock, int $body, int $imports = 0): string
    {
        $header = "<?php\n\nnamespace {$namespace};\n\n".str_repeat("use DateTimeImmutable;\n", min($imports, 1)).str_repeat("// import\n", max($imports - 1, 0))."\n";
        $doc = $docblock > 0 ? "/**\n".str_repeat(" * Line.\n", $docblock - 2)." */\n" : '';

        $properties = '';
        for ($i = 0; $i < $body; ++$i) {
            $properties .= "    public int \$p{$i} = 0;\n";
        }

        return $header.$doc."final class {$class}\n{\n".$properties."}\n";
    }
}
