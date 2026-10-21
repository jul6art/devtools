<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Assertion;

use Dom\Element;
use Dom\HTMLDocument;
use Jul6Art\DevTools\Project\ProjectRoot;
use Jul6Art\DevTools\Scenario\Run\XmlRunReader;
use Jul6Art\DevTools\Xml\InvalidXml;

/**
 * Checks assertions (ADR-0027): a pattern once per watched file, a DOM selector on the captures already written —
 * no browser started — and a tool **once** per command for all the assertions that call it.
 */
final readonly class AssertionChecker
{
    /**
     * @param string|null $run the `result.xml` whose captures a DOM assertion reads; null: there is none
     */
    public function __construct(private ProjectRoot $root, private ToolRunner $tools = new ToolRunner(), private ?string $run = null)
    {
    }

    /**
     * @param list<Assertion>   $assertions
     * @param list<string>|null $scope      the files to judge — those of a change; null: every file each one watches
     *
     * @return list<AssertionResult> in the order of the assertions
     */
    public function check(array $assertions, FileSource $source, ?array $scope = null): array
    {
        $files = array_map(static fn (Assertion $assertion): array => $assertion->watched($scope ?? $source->files()), $assertions);
        $commands = [];

        foreach ($assertions as $index => $assertion) {
            if ($assertion->check instanceof ToolCheck && $source->isCurrent() && [] !== $files[$index]) {
                $commands[$assertion->check->command] = [...$commands[$assertion->check->command] ?? [], ...$files[$index]];
            }
        }

        $outputs = [];

        foreach ($commands as $command => $watched) {
            $watched = array_values(array_unique($watched));
            sort($watched, \SORT_STRING);
            $outputs[$command] = $this->tools->run($this->root, $command, $watched);
        }

        return array_map(
            fn (Assertion $assertion, array $watched): AssertionResult => match (true) {
                [] === $watched && !$assertion->check instanceof DomCheck => new AssertionResult($assertion, unverifiable: \sprintf('%s watches no file here: %s.', $assertion->id, implode(', ', $assertion->watch))),
                $assertion->check instanceof PatternCheck => $this->pattern($assertion, $assertion->check, $source, $watched),
                $assertion->check instanceof DomCheck => $this->dom($assertion, $assertion->check, $source),
                default => $this->tool($assertion, $assertion->check, $source, $watched, $outputs[$assertion->check->command] ?? null),
            },
            $assertions,
            $files,
        );
    }

    /**
     * @param list<string> $files
     */
    private function pattern(Assertion $assertion, PatternCheck $check, FileSource $source, array $files): AssertionResult
    {
        $error = $check->error();

        if (null !== $error) {
            return new AssertionResult($assertion, unverifiable: $error, files: $files);
        }

        $violations = [];

        foreach ($files as $file) {
            $text = $source->read($file);

            if (null === $text) {
                continue;
            }

            if (Expect::MustMatch === $check->expect) {
                if (1 !== preg_match($check->regex, $text)) {
                    $violations[] = new Violation($file, null, \sprintf('nothing matches %s', $check->regex));
                }

                continue;
            }

            preg_match_all($check->regex, $text, $matches, \PREG_OFFSET_CAPTURE);

            foreach ($matches[0] as [, $offset]) {
                $violations[] = new Violation($file, substr_count($text, "\n", 0, $offset) + 1, self::line($text, $offset));
            }
        }

        return new AssertionResult($assertion, $violations, files: $files);
    }

    private function dom(Assertion $assertion, DomCheck $check, FileSource $source): AssertionResult
    {
        if (!$source->isCurrent()) {
            return new AssertionResult($assertion, unverifiable: 'a DOM assertion reads the captures of a run, not a commit.');
        }

        if (null === $this->run || !is_file($this->run)) {
            return new AssertionResult($assertion, unverifiable: \sprintf('no scenario was replayed, so no DOM of %s to read: scenarios:run captures it.', $check->scenario));
        }

        try {
            $scenarios = new XmlRunReader()->read($this->run);
        } catch (InvalidXml $invalid) {
            return new AssertionResult($assertion, unverifiable: $invalid->getMessage());
        }

        $captures = [];

        foreach ($scenarios as $scenario => $result) {
            foreach ($result['captures'] as $capture) {
                if ($check->covers($scenario, basename($capture['name']))) {
                    $captures[] = $this->root->relative(\dirname($this->run).'/'.$capture['dom']);
                }
            }
        }

        if ([] === $captures) {
            return new AssertionResult($assertion, unverifiable: \sprintf('the run %s has no capture of %s%s.', basename(\dirname($this->run)), $check->scenario, null === $check->capture ? '' : ' named '.$check->capture));
        }

        $found = [];

        foreach ($captures as $capture) {
            try {
                $document = HTMLDocument::createFromString((string) file_get_contents($this->root->absolute($capture)), \LIBXML_NOERROR);
                $found[$capture] = $document->querySelector($check->selector) instanceof Element;
            } catch (\Throwable $error) {
                return new AssertionResult($assertion, unverifiable: \sprintf('"%s" is not a selector the DOM reads: %s', $check->selector, $error->getMessage()), files: $captures);
            }
        }

        $violations = Expect::MustExist === $check->expect
            ? (\in_array(true, $found, true) ? [] : array_map(static fn (string $capture): Violation => new Violation($capture, null, \sprintf('no element matches %s', $check->selector)), $captures))
            : array_map(static fn (string $capture): Violation => new Violation($capture, null, \sprintf('%s is there', $check->selector)), array_keys(array_filter($found)));

        return new AssertionResult($assertion, $violations, files: $captures);
    }

    /**
     * @param list<string> $files
     */
    private function tool(Assertion $assertion, ToolCheck $check, FileSource $source, array $files, ?ToolOutput $output): AssertionResult
    {
        if (!$source->isCurrent() || !$output instanceof ToolOutput) {
            return new AssertionResult($assertion, unverifiable: 'a tool assertion runs on the project as it is, not on a commit.');
        }

        $findings = ToolFindings::read($check, $output, $this->root);

        if (\is_string($findings)) {
            return new AssertionResult($assertion, unverifiable: $findings, files: $files);
        }

        // An exit code says nothing of where: it is the tool's whole answer.
        $violations = ToolFormat::ExitCode === $check->format ? $findings : array_values(array_filter($findings, static fn (Violation $violation): bool => \in_array($violation->file, $files, true)));

        return new AssertionResult($assertion, $violations, files: $files);
    }

    /**
     * The line of the text a match starts on, trimmed, as it can be shown.
     */
    private static function line(string $text, int $offset): string
    {
        $start = (int) strrpos(substr($text, 0, $offset), "\n");
        $end = strpos($text, "\n", $offset);

        return mb_substr(trim(substr($text, $start, false === $end ? null : $end - $start)), 0, 120);
    }
}
