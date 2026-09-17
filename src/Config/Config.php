<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Config;

use Jul6Art\DevTools\Inspection\Model\InvalidModel;
use Jul6Art\DevTools\Inspection\Model\WorkflowId;
use Jul6Art\DevTools\Inspection\Model\WorkflowType;

/**
 * The options of DevTools for one project, with their defaults (`.devtools/config.xml`, ADR-0004).
 *
 * Every option has a default, so a project without the file — or with the file `init` writes — gets
 * exactly this object. The file is the human's: DevTools reads it and never rewrites it.
 */
final readonly class Config
{
    /**
     * Never scanned, whatever the configuration says: configured exclusions add to these.
     */
    public const array DEFAULT_EXCLUDES = ['.devtools', '.git', 'build', 'dist', 'node_modules', 'var', 'vendor'];

    /**
     * @var list<string>
     */
    private array $excludes;

    /**
     * @param list<string>                $excludes    added to {@see self::DEFAULT_EXCLUDES}
     * @param array<string, string>       $aliases     entry point name => workflow identifier (ADR-0003)
     * @param array<string, list<string>> $groups      main entry point => satellites (ADR-0006)
     * @param list<WorkflowType>          $customTypes
     * @param string|null                 $phpWebRoot  the web directory of a PHP project without framework; null to look for public/, web/, www/ (ADR-0014)
     */
    public function __construct(
        array $excludes = [],
        public int $graphDepth = 3,
        public string $routePrefix = 'app_',
        public array $aliases = [],
        public array $groups = [],
        public array $customTypes = [],
        public string $symfonyConsole = 'bin/console',
        public string $symfonyEnv = 'dev',
        public float $symfonyTimeout = 60.0,
        public string $pagesLanguage = 'fr',
        public ?string $phpWebRoot = null,
    ) {
        if ($graphDepth < 0) {
            throw new InvalidConfig('The graph depth cannot be negative.');
        }

        if ($symfonyTimeout <= 0) {
            throw new InvalidConfig('The console timeout must be a positive number of seconds.');
        }

        foreach ($aliases as $entryPoint => $id) {
            try {
                new WorkflowId($id);
            } catch (InvalidModel $invalid) {
                throw new InvalidConfig(\sprintf('The alias of the entry point "%s" is invalid: %s', $entryPoint, $invalid->getMessage()), 0, $invalid);
            }
        }

        $excludes = array_values(array_unique([...self::DEFAULT_EXCLUDES, ...array_map(static fn (string $path): string => trim($path, '/'), $excludes)]));
        sort($excludes, \SORT_STRING);
        $this->excludes = $excludes;
    }

    /**
     * @return list<string>
     */
    public function excludes(): array
    {
        return $this->excludes;
    }
}
