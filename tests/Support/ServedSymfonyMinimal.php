<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Tests\Support;

use Jul6Art\DevTools\Clock\FrozenClock;
use Jul6Art\DevTools\Inspection\Adapter\Symfony\SymfonyAdapter;
use Jul6Art\DevTools\Inspection\AdapterResolver;
use Jul6Art\DevTools\Inspection\InspectionOptions;
use Jul6Art\DevTools\Inspection\InspectionPipeline;
use Jul6Art\DevTools\Inspection\Model\WorkflowTypeRegistry;
use Jul6Art\DevTools\Project\ProjectRoot;
use Jul6Art\DevTools\Scenario\ScenarioGenerator;
use Jul6Art\DevTools\Tests\Inspection\Adapter\Symfony\RecordedConsoleRunner;
use Jul6Art\DevTools\Tracking\DevToolsDirectory;
use Symfony\Component\Filesystem\Filesystem;

/**
 * symfony-minimal ready to be served and replayed (ADR-0021): copied with its `vendor/`, given the two files of
 * `tests/Fixtures/runtime/` it lacks to answer a browser, inspected, its skeletons written, and its logins
 * declared — HTTP basic, from `SYMFONY_MINIMAL_*` variables the tests set.
 *
 * Its `vendor/` is installed locally (`composer install` in the fixture), not in CI: without it, the tests that
 * need it are skipped.
 */
final class ServedSymfonyMinimal
{
    public const array ENVIRONMENT = [
        'SYMFONY_MINIMAL_USER' => 'user',
        'SYMFONY_MINIMAL_USER_PASSWORD' => 'user',
        'SYMFONY_MINIMAL_OPERATOR' => 'operator',
        'SYMFONY_MINIMAL_OPERATOR_PASSWORD' => 'operator',
    ];

    private const string FIXTURE = __DIR__.'/../Fixtures/projects/symfony-minimal';

    public static function missing(): ?string
    {
        return match (true) {
            null === Browser::driver() => 'ChromeDriver not found: vendor/bin/bdi detect drivers, or chromedriver on the PATH.',
            !is_file(self::FIXTURE.'/vendor/autoload_runtime.php') => 'symfony-minimal has no vendor/: run composer install in tests/Fixtures/projects/symfony-minimal.',
            default => null,
        };
    }

    public static function prepare(string $target): string
    {
        $filesystem = new Filesystem();
        $filesystem->mirror(self::FIXTURE, $target, new \RecursiveIteratorIterator(new \RecursiveCallbackFilterIterator(
            new \RecursiveDirectoryIterator(self::FIXTURE, \FilesystemIterator::SKIP_DOTS),
            static fn (\SplFileInfo $file): bool => !\in_array($file->getFilename(), ['var', '.devtools'], true),
        ), \RecursiveIteratorIterator::SELF_FIRST));
        $filesystem->mirror(__DIR__.'/../Fixtures/runtime/symfony-minimal', $target, options: ['override' => true]);

        new InspectionPipeline(new AdapterResolver([new SymfonyAdapter(new RecordedConsoleRunner())]), new FrozenClock(new \DateTimeImmutable('2026-09-16T15:00:00+02:00')))->run(new InspectionOptions($target, noAi: true));
        $directory = DevToolsDirectory::documented(new ProjectRoot($target));
        new ScenarioGenerator()->generate($directory, new WorkflowTypeRegistry(), noAi: true);
        file_put_contents($directory->configFile(), <<<'XML'
            <devtools xmlns="https://github.com/jul6art/devtools/schema/config/1" schema-version="1">
                <scenarios timeout="5">
                    <login role="ROLE_USER" type="http-basic" username-env="SYMFONY_MINIMAL_USER" password-env="SYMFONY_MINIMAL_USER_PASSWORD"/>
                    <login role="ROLE_OPERATOR" type="http-basic" username-env="SYMFONY_MINIMAL_OPERATOR" password-env="SYMFONY_MINIMAL_OPERATOR_PASSWORD"/>
                </scenarios>
            </devtools>
            XML);

        return $target;
    }

    public static function exportCredentials(bool $set = true): void
    {
        foreach (self::ENVIRONMENT as $name => $value) {
            putenv($set ? $name.'='.$value : $name);
        }
    }
}
