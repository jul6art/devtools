<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Scenario\Runner;

use Facebook\WebDriver\Chrome\ChromeDevToolsDriver;
use Facebook\WebDriver\Exception\TimeoutException;
use Facebook\WebDriver\Remote\RemoteWebDriver;
use Facebook\WebDriver\WebDriverBy;
use Facebook\WebDriver\WebDriverExpectedCondition;
use Jul6Art\DevTools\Config\LoginSettings;
use Jul6Art\DevTools\Config\ScenarioSettings;
use Jul6Art\DevTools\Scenario\Action\Elements;
use Symfony\Component\Panther\Client;

/**
 * The logins of `.devtools/config.xml` (ADR-0021): a login form filled in, or HTTP basic authentication sent
 * with every request — the credentials read from the environment, and handed to the run's secrets so that
 * nothing it writes repeats them.
 */
final readonly class ConfiguredLoginProvider implements LoginProviderInterface
{
    public function __construct(private ScenarioSettings $settings, private Secrets $secrets, private int $timeout = ScenarioSettings::DEFAULT_TIMEOUT)
    {
    }

    #[\Override]
    public function login(Client $client, string $role): void
    {
        $login = $this->settings->login($role) ?? throw new \RuntimeException(\sprintf('No login for the role "%s" in the <scenarios> of .devtools/config.xml.', $role));
        $username = self::environment($login->usernameEnv);
        $password = self::environment($login->passwordEnv);
        $this->secrets->add($username);
        $this->secrets->add($password);

        if (LoginSettings::HTTP_BASIC === $login->type) {
            self::devTools($client)->execute('Network.setExtraHTTPHeaders', ['headers' => ['Authorization' => 'Basic '.base64_encode($username.':'.$password)]]);

            return;
        }

        $client->request('GET', (string) $login->url);
        Elements::field($client, $login->usernameField, $this->timeout)->sendKeys($username);
        $passwordField = Elements::field($client, $login->passwordField, $this->timeout);
        $passwordField->sendKeys($password);

        if (null === $login->submit) {
            $passwordField->submit();
        } else {
            Elements::clickable($client, $login->submit, $this->timeout)->click();
        }

        // The form leaves, the next page loads: judged before that, a page half gone has no password field either.
        try {
            $client->wait($this->timeout, 100)->until(WebDriverExpectedCondition::stalenessOf($passwordField));
        } catch (TimeoutException) {
            // No navigation: an application that answers in place — what it shows now is the answer.
        }

        Elements::eventually($client, $this->timeout, static fn (): bool => 'complete' === $client->executeScript('return document.readyState;'));

        // Still asked for a password: the credentials were refused.
        if ([] !== $client->findElements(WebDriverBy::name($login->passwordField))) {
            throw new \RuntimeException(\sprintf('The login form of "%s" did not let it in: check %s and %s.', $role, $login->usernameEnv, $login->passwordEnv));
        }
    }

    /**
     * A clean browser for the next scenario: no cookie, no header of the previous login.
     */
    public static function reset(Client $client): void
    {
        $devTools = self::devTools($client);
        $devTools->execute('Network.setExtraHTTPHeaders', ['headers' => new \stdClass()]);
        $devTools->execute('Network.clearBrowserCookies');
    }

    private static function devTools(Client $client): ChromeDevToolsDriver
    {
        $driver = $client->getWebDriver();

        if (!$driver instanceof RemoteWebDriver) {
            throw new \RuntimeException('HTTP basic authentication needs Chrome.');
        }

        $devTools = new ChromeDevToolsDriver($driver);
        $devTools->execute('Network.enable');

        return $devTools;
    }

    private static function environment(string $name): string
    {
        $value = getenv($name);

        return \is_string($value) && '' !== $value ? $value : throw new \RuntimeException(\sprintf('The environment variable %s is not set: the login reads its credential there.', $name));
    }
}
