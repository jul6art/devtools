<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Scenario\Runner;

/**
 * The credentials a run used, so that nothing it writes — a message, a URL, a DOM — ever carries them
 * (ADR-0021).
 */
final class Secrets
{
    public const string MASK = '***';

    /**
     * @var array<string, true>
     */
    private array $values = [];

    public function add(string $value): void
    {
        if ('' === $value) {
            return;
        }

        foreach ([$value, rawurlencode($value), urlencode($value), htmlspecialchars($value, \ENT_QUOTES), base64_encode($value)] as $form) {
            $this->values[$form] = true;
        }
    }

    public function redact(string $text): string
    {
        // Credentials in a URL, whoever put them there.
        $text = (string) preg_replace('#\b([a-z][a-z0-9+.-]*://)[^/@\s]+@#i', '$1', $text);
        $values = array_map(strval(...), array_keys($this->values));
        usort($values, static fn (string $a, string $b): int => \strlen($b) <=> \strlen($a));

        return [] === $values ? $text : str_replace($values, self::MASK, $text);
    }
}
