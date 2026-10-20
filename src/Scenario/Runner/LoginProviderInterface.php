<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Scenario\Runner;

use Symfony\Component\Panther\Client;

/**
 * Logs the browser in as a role a scenario names (ADR-0021). The project provides it: in `.devtools/config.xml`
 * for the URL mode, as a service for PHPUnit. A scenario never holds a credential.
 */
interface LoginProviderInterface
{
    /**
     * @throws \RuntimeException when the role cannot log in
     */
    public function login(Client $client, string $role): void;
}
