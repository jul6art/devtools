<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Assertion;

use Jul6Art\DevTools\Project\PathOutsideProject;
use Jul6Art\DevTools\Project\ProjectRoot;

/**
 * What a tool reported, read in its own format (ADR-0027): a PHPStan or ESLint JSON report, or an exit code.
 */
final class ToolFindings
{
    /**
     * @return list<Violation>|string the findings, relative to the project; or why the output cannot be read
     */
    public static function read(ToolCheck $check, ToolOutput $output, ProjectRoot $root): array|string
    {
        if (null === $output->exitCode) {
            return \sprintf('"%s" did not finish: %s.', $check->command, $output->firstLine());
        }

        if (ToolFormat::ExitCode === $check->format) {
            return 0 === $output->exitCode ? [] : [new Violation(null, null, $output->firstLine())];
        }

        $report = json_decode($output->output, true);
        $findings = match ($check->format) {
            ToolFormat::Phpstan => self::phpstan($report),
            default => self::eslint($report),
        };

        if (null === $findings) {
            return \sprintf('"%s" did not answer as %s --format json: %s', $check->command, $check->format->value, $output->firstLine());
        }

        $violations = [];

        foreach ($findings as [$file, $line, $identifier, $message]) {
            if (null === $check->identifier || $identifier === $check->identifier) {
                $violations[] = new Violation(self::relative($file, $root), $line, $message);
            }
        }

        return $violations;
    }

    /**
     * @return list<array{string, ?int, ?string, string}>|null
     */
    private static function phpstan(mixed $report): ?array
    {
        if (!\is_array($report) || !\is_array($report['files'] ?? null)) {
            return null;
        }

        $findings = [];

        foreach ($report['files'] as $file => $result) {
            foreach (\is_array($result) && \is_array($result['messages'] ?? null) ? $result['messages'] : [] as $message) {
                if (\is_array($message)) {
                    $findings[] = [(string) $file, \is_int($message['line'] ?? null) ? $message['line'] : null, \is_string($message['identifier'] ?? null) ? $message['identifier'] : null, \is_string($message['message'] ?? null) ? $message['message'] : ''];
                }
            }
        }

        return $findings;
    }

    /**
     * @return list<array{string, ?int, ?string, string}>|null
     */
    private static function eslint(mixed $report): ?array
    {
        if (!\is_array($report) || !array_is_list($report)) {
            return null;
        }

        $findings = [];

        foreach ($report as $result) {
            if (!\is_array($result) || !\is_string($result['filePath'] ?? null) || !\is_array($result['messages'] ?? null)) {
                continue;
            }

            $file = $result['filePath'];

            foreach ($result['messages'] as $message) {
                if (\is_array($message)) {
                    $findings[] = [$file, \is_int($message['line'] ?? null) ? $message['line'] : null, \is_string($message['ruleId'] ?? null) ? $message['ruleId'] : null, \is_string($message['message'] ?? null) ? $message['message'] : ''];
                }
            }
        }

        return $findings;
    }

    /**
     * A tool says where as it likes: absolute, resolved or not, or relative to where it ran.
     */
    private static function relative(string $file, ProjectRoot $root): string
    {
        foreach (array_unique([$file, realpath($file) ?: $file]) as $candidate) {
            try {
                return $root->relative($root->absolute($candidate));
            } catch (PathOutsideProject) {
                // Not the project's, written this way: the next way, if any.
            }
        }

        return $file;
    }
}
