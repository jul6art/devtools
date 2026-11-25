<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Bridge\PHPStan;

use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\BinaryOp\Concat;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\NullsafePropertyFetch;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\StaticPropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\InterpolatedStringPart;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\InterpolatedString;
use PhpParser\Node\Scalar\String_;

/**
 * Whether a call reads the time without going through the project's clock, and what to say about it (ADR-0055).
 *
 * The decision lives here, without PHPStan: {@see TimeReadingRule} hands it the call, the names PHPStan resolved and
 * the constant strings it knows; {@see \Jul6Art\DevTools\Bridge\Rector\UseClockRector} asks it which date strings are
 * relative — so the rule and the rewrite agree on what a reading is.
 *
 * ⚠️ **The message never carries a value or a line**, only the call: a reading a project keeps is frozen in a
 * baseline, which compares messages.
 */
final readonly class TimeReading
{
    public const string DEFAULT_CLOCK = 'Jul6Art\CoreBundle\Util\Clock';

    /**
     * The clock reads the time, and the test trait freezes it: they are where the time is read.
     *
     * @var list<string>
     */
    public const array DEFAULT_ALLOWED = ['Jul6Art\CoreBundle\Util\Clock', 'Jul6Art\CoreBundle\Test\ClockTestTrait'];

    /** @var list<string> */
    public const array DEFAULT_TESTS = ['*/tests/*', '*/Tests/*'];

    private const string ABSOLUTE = '/^\s*(@-?\d+|\d{4}-\d{1,2}-\d{1,2}|\d{4}\/\d{1,2}\/\d{1,2}|\d{1,2}\/\d{1,2}\/\d{4}|\d{1,2}\.\d{1,2}\.\d{4}|\d{8}|\d{4}-W\d)/';

    private const string DELAY_NAME = '/interval|modifier|relative|ago|delay|ttl|lifetime|retention|threshold/i';

    private const string RELATIVE_WORD = '/\b(now|today|tomorrow|yesterday|midnight|noon|ago|first|last|next|this|secs?|seconds?|mins?|minutes?|hours?|days?|weeks?|fortnight|months?|years?|monday|tuesday|wednesday|thursday|friday|saturday|sunday)\b/i';

    private const array SLEEPS = ['sleep', 'usleep', 'time_nanosleep', 'time_sleep_until'];

    private const array SYMFONY_CLOCK = ['symfony\component\clock\clock', 'symfony\component\clock\datepoint', 'symfony\component\clock\nativeclock'];

    /**
     * @param string       $clock   the class the messages send the reader to
     * @param list<string> $allowed classes and traits that read the time themselves
     * @param list<string> $tests   fnmatch() patterns on the path of a test file, where only the waits are reported
     */
    public function __construct(
        private string $clock = self::DEFAULT_CLOCK,
        private array $allowed = self::DEFAULT_ALLOWED,
        private array $tests = self::DEFAULT_TESTS,
    ) {
    }

    /**
     * Whether code in this class, or in this trait, may read the time itself.
     */
    public function allows(?string $class, ?string $trait): bool
    {
        return \in_array($class, $this->allowed, true) || \in_array($trait, $this->allowed, true);
    }

    /**
     * `new $class(…)`.
     *
     * @param list<Arg>    $args
     * @param list<string> $constants the values PHPStan knows the first argument can take, none when it does not know
     *
     * @return array{message: string, tip: string}|null
     */
    public function ofConstruction(string $class, array $args, array $constants, string $file): ?array
    {
        $lower = strtolower(ltrim($class, '\\'));

        if ($this->isTest($file)) {
            return null;
        }

        if (\in_array($lower, self::SYMFONY_CLOCK, true)) {
            return $this->symfony('new '.ltrim($class, '\\'));
        }

        if (!\in_array($lower, ['datetime', 'datetimeimmutable'], true) || !self::isRelative($args[0]->value ?? null, $constants)) {
            return null;
        }

        return [
            'message' => \sprintf('new \%s() reads the system clock.', ltrim($class, '\\')),
            'tip' => \sprintf('%s::now() takes the same arguments and gives the same date, from the clock a test can freeze.', $this->clock),
        ];
    }

    /**
     * `$function(…)`.
     *
     * @param list<Arg>    $args
     * @param list<string> $constants the values PHPStan knows the first argument can take, none when it does not know
     *
     * @return array{message: string, tip: string}|null
     */
    public function ofFunction(string $function, array $args, array $constants, string $file): ?array
    {
        $name = strtolower(ltrim($function, '\\'));

        if (\in_array($name, self::SLEEPS, true)) {
            return [
                'message' => \sprintf('%s() waits on the system clock.', $name),
                'tip' => $this->isTest($file)
                    ? 'A test does not wait: freeze the clock and move it with ClockTestTrait::advanceClock().'
                    : \sprintf('%s::sleep() waits the same, and a frozen clock moves instead.', $this->clock),
            ];
        }

        if ($this->isTest($file)) {
            return null;
        }

        if ('symfony\component\clock\now' === $name) {
            return $this->symfony('Symfony\Component\Clock\now()');
        }

        if (\in_array($name, ['date_create_from_format', 'date_create_immutable_from_format'], true)) {
            return $this->ofFormat($name, $args[0] ?? null);
        }

        $count = \count($args);
        $reads = match ($name) {
            'time' => true,
            'date', 'gmdate', 'idate' => 1 === $count,
            'getdate', 'localtime' => 0 === $count,
            'mktime', 'gmmktime' => $count < 6,
            'strtotime' => 1 === $count && self::isRelative($args[0]->value, $constants),
            'date_create', 'date_create_immutable' => 0 === $count || self::isRelative($args[0]->value, $constants),
            default => false,
        };

        return $reads ? [
            'message' => \sprintf('%s() reads the system clock.', $name),
            'tip' => \sprintf('Read it through %s: now() and timestamp() give the same, from the clock a test can freeze.', $this->clock),
        ] : null;
    }

    /**
     * `$class::$method(…)`.
     *
     * @param list<Arg> $args
     *
     * @return array{message: string, tip: string}|null
     */
    public function ofStaticCall(string $class, string $method, array $args, string $file): ?array
    {
        $lower = strtolower(ltrim($class, '\\'));

        if ($this->isTest($file)) {
            return null;
        }

        if (\in_array($lower, self::SYMFONY_CLOCK, true)) {
            return $this->symfony(ltrim($class, '\\').'::'.$method.'()');
        }

        if (\in_array($lower, ['datetime', 'datetimeimmutable'], true) && 'createfromformat' === strtolower($method)) {
            return $this->ofFormat('createFromFormat', $args[0] ?? null);
        }

        return null;
    }

    /**
     * Whether a date string reads the clock: none at all, `'now'`, a relative date, or one being built with a relative
     * word or from a delay (`'-'.$interval`). A string that starts with a full date or a timestamp does not; a value of
     * which nothing says it is relative is taken as a conversion.
     *
     * @param list<string> $constants the values PHPStan knows the string can take, none when it does not know
     */
    public static function isRelative(?Expr $string, array $constants = []): bool
    {
        if (!$string instanceof Expr) {
            return true;
        }

        if ([] !== $constants) {
            return array_any($constants, static fn (string $constant): bool => 1 !== preg_match(self::ABSOLUTE, $constant));
        }

        if ($string instanceof String_) {
            return 1 !== preg_match(self::ABSOLUTE, $string->value);
        }

        [$pieces, $names] = self::parts($string);

        if ([] !== $pieces && 1 === preg_match(self::ABSOLUTE, $pieces[0])) {
            return false;
        }

        return array_any($pieces, static fn (string $piece): bool => 1 === preg_match(self::RELATIVE_WORD, $piece))
            || array_any($names, static fn (string $name): bool => 1 === preg_match(self::DELAY_NAME, $name));
    }

    private function isTest(string $file): bool
    {
        return array_any($this->tests, static fn (string $pattern): bool => fnmatch($pattern, $file));
    }

    /**
     * A literal format that leaves fields out without "!" or "|" takes them from the current time.
     *
     * @return array{message: string, tip: string}|null
     */
    private function ofFormat(string $call, ?Arg $format): ?array
    {
        if (!$format?->value instanceof String_) {
            return null;
        }

        $fields = preg_replace('/\\\\./', '', $format->value->value) ?? '';

        if (str_contains($fields, '!') || str_contains($fields, '|') || str_contains($fields, 'U')) {
            return null;
        }

        $complete = 1 === preg_match('/[YyXx]/', $fields) && 1 === preg_match('/[mnMF]/', $fields) && 1 === preg_match('/[dj]/', $fields)
            && 1 === preg_match('/[HGhg]/', $fields) && str_contains($fields, 'i') && str_contains($fields, 's');

        return $complete ? null : [
            'message' => \sprintf('%s() fills the fields its format leaves out with the current time.', $call),
            'tip' => 'Start the format with "!" or end it with "|": the missing fields are then zero, whatever the clock.',
        ];
    }

    /**
     * @return array{message: string, tip: string}
     */
    private function symfony(string $call): array
    {
        return [
            'message' => \sprintf('%s is Symfony Clock, which only the project\'s clock wraps.', $call),
            'tip' => \sprintf('Read the time through %s; a service injects Psr\Clock\ClockInterface.', $this->clock),
        ];
    }

    /**
     * The literal pieces of a string being built, in order, and the names of what fills it.
     *
     * @return array{list<string>, list<string>}
     */
    private static function parts(Expr $expr): array
    {
        if ($expr instanceof String_) {
            return [[$expr->value], []];
        }

        $children = match (true) {
            $expr instanceof Concat => [$expr->left, $expr->right],
            $expr instanceof InterpolatedString => $expr->parts,
            $expr instanceof FuncCall => array_map(static fn (Arg $arg): Expr => $arg->value, array_values(array_filter($expr->args, static fn (mixed $arg): bool => $arg instanceof Arg))),
            default => null,
        };

        if (null === $children) {
            return [[], self::names($expr)];
        }

        $pieces = [];
        $names = $expr instanceof FuncCall && $expr->name instanceof Name ? [$expr->name->getLast()] : [];

        foreach ($children as $child) {
            if ($child instanceof InterpolatedStringPart) {
                $pieces[] = $child->value;

                continue;
            }

            [$childPieces, $childNames] = self::parts($child);
            $pieces = [...$pieces, ...$childPieces];
            $names = [...$names, ...$childNames];
        }

        return [$pieces, $names];
    }

    /**
     * What a value is called: `$interval`, `$this->retention`, `getDelay()`, `self::TTL`.
     *
     * @return list<string>
     */
    private static function names(Expr $expr): array
    {
        $name = match (true) {
            $expr instanceof Variable, $expr instanceof PropertyFetch, $expr instanceof NullsafePropertyFetch,
            $expr instanceof StaticPropertyFetch, $expr instanceof MethodCall, $expr instanceof NullsafeMethodCall,
            $expr instanceof StaticCall, $expr instanceof ClassConstFetch => $expr->name,
            $expr instanceof ConstFetch => $expr->name->getLast(),
            default => null,
        };

        $names = match (true) {
            \is_string($name) => [$name],
            $name instanceof Identifier => [$name->toString()],
            default => [],
        };

        if ($expr instanceof MethodCall || $expr instanceof NullsafeMethodCall || $expr instanceof PropertyFetch || $expr instanceof NullsafePropertyFetch) {
            return [...$names, ...self::names($expr->var)];
        }

        return $names;
    }
}
