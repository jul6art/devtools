<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Scenario\Action;

use Facebook\WebDriver\Exception\TimeoutException;
use Facebook\WebDriver\WebDriverBy;
use Facebook\WebDriver\WebDriverElement;
use Jul6Art\DevTools\Scenario\Runner\StepFailed;
use Symfony\Component\Panther\Client;

/**
 * Finding what a step names, waiting for it as long as the step may — never a fixed pause.
 *
 * @internal
 */
final class Elements
{
    /**
     * A form field by its name, its id or the text of its label — the order a person reads a form in.
     */
    public static function field(Client $client, string $field, int $timeout): WebDriverElement
    {
        return self::first($client, $timeout, \sprintf('no field "%s" (by name, id or label)', $field), [
            WebDriverBy::name($field),
            WebDriverBy::id($field),
            WebDriverBy::xpath(\sprintf('//*[@id=//label[normalize-space()=%s]/@for]', self::literal($field))),
            WebDriverBy::xpath(\sprintf('//label[normalize-space()=%s]//*[self::input or self::select or self::textarea]', self::literal($field))),
        ]);
    }

    /**
     * What a person clicks, by its text: a button, a link, a submit input.
     */
    public static function clickable(Client $client, string $text, int $timeout): WebDriverElement
    {
        $literal = self::literal($text);

        return self::first($client, $timeout, \sprintf('nothing to click reads "%s"', $text), [
            WebDriverBy::xpath(\sprintf('//button[normalize-space()=%1$s] | //a[normalize-space()=%1$s] | //input[(@type="submit" or @type="button") and @value=%1$s] | //*[@role="button" and normalize-space()=%1$s]', $literal)),
        ]);
    }

    public static function css(Client $client, string $selector, int $timeout): WebDriverElement
    {
        return self::first($client, $timeout, \sprintf('nothing matches "%s"', $selector), [WebDriverBy::cssSelector($selector)]);
    }

    /**
     * @param list<WebDriverBy> $locators tried in order, until one finds a displayed element
     *
     * @throws StepFailed when none does in time
     */
    public static function first(Client $client, int $timeout, string $missing, array $locators): WebDriverElement
    {
        try {
            $found = $client->wait($timeout, 100)->until(static function () use ($client, $locators): ?WebDriverElement {
                foreach ($locators as $locator) {
                    foreach ($client->findElements($locator) as $element) {
                        if ($element->isDisplayed() || 'hidden' !== $element->getAttribute('type')) {
                            return $element;
                        }
                    }
                }

                return null;
            });
        } catch (TimeoutException) {
            throw new StepFailed(\sprintf('%s after %d s.', ucfirst($missing), $timeout));
        }

        return $found instanceof WebDriverElement ? $found : throw new StepFailed(ucfirst($missing).'.');
    }

    /**
     * Waits for a condition the step asserts; false after the timeout is the step failing.
     *
     * @param \Closure(): bool $condition
     */
    public static function eventually(Client $client, int $timeout, \Closure $condition): bool
    {
        try {
            return true === $client->wait($timeout, 100)->until(static fn (): ?bool => $condition() ? true : null);
        } catch (TimeoutException) {
            return false;
        }
    }

    /**
     * A string as an XPath literal, whatever quotes it holds.
     */
    public static function literal(string $text): string
    {
        if (!str_contains($text, '"')) {
            return '"'.$text.'"';
        }

        if (!str_contains($text, "'")) {
            return "'".$text."'";
        }

        return 'concat("'.str_replace('"', '", \'"\', "', $text).'")';
    }
}
