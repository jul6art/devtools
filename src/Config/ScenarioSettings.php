<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Config;

/**
 * How `scenarios:run` reaches the application (ADR-0021): where it is served, how long a step may wait, and
 * how each role logs in and each fixture is loaded — names a scenario uses, resolved here.
 *
 * No credential is ever written in this file: a login names the environment variables that hold them.
 */
final readonly class ScenarioSettings
{
    /**
     * Seconds a step waits for what it looks for.
     */
    public const int DEFAULT_TIMEOUT = 10;

    /**
     * @var list<LoginSettings>
     */
    public array $logins;

    /**
     * @var list<FixtureSettings>
     */
    public array $fixtures;

    /**
     * @param list<LoginSettings>   $logins
     * @param list<FixtureSettings> $fixtures
     */
    public function __construct(public ?string $baseUrl = null, public int $timeout = self::DEFAULT_TIMEOUT, array $logins = [], array $fixtures = [], public VisualSettings $visual = new VisualSettings())
    {
        if ($timeout <= 0) {
            throw new InvalidConfig('The timeout of a scenario step must be a positive number of seconds.');
        }

        foreach (array_count_values(array_map(static fn (LoginSettings $login): string => $login->role, $logins)) as $role => $count) {
            if ($count > 1) {
                throw new InvalidConfig(\sprintf('The role "%s" logs in twice in <scenarios>.', $role));
            }
        }

        foreach (array_count_values(array_map(static fn (FixtureSettings $fixture): string => $fixture->name, $fixtures)) as $name => $count) {
            if ($count > 1) {
                throw new InvalidConfig(\sprintf('The fixture "%s" is declared twice in <scenarios>.', $name));
            }
        }

        $this->logins = $logins;
        $this->fixtures = $fixtures;
    }

    public function login(string $role): ?LoginSettings
    {
        return array_find($this->logins, static fn (LoginSettings $login): bool => $login->role === $role);
    }

    public function fixture(string $name): ?FixtureSettings
    {
        return array_find($this->fixtures, static fn (FixtureSettings $fixture): bool => $fixture->name === $name);
    }
}
