<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Bridge\Rector;

use Jul6Art\DevTools\Bridge\PHPStan\TimeReading;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Attribute;
use PhpParser\Node\Const_;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\BinaryOp\Div;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\Param;
use PhpParser\Node\Scalar\Int_;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\StaticVar;
use PHPStan\Type\Constant\ConstantStringType;
use Rector\Contract\Rector\ConfigurableRectorInterface;
use Rector\Rector\AbstractRector;

/**
 * Replaces the readings of the time that the project's clock covers one for one, with the same result under the
 * system clock (ADR-0055):
 *
 * - `new \DateTimeImmutable()`, `'now'` or a relative date → `Clock::now(…)`, same arguments;
 * - `time()` → `Clock::timestamp()`; `date($format)` → `Clock::now()->format($format)`;
 * - `strtotime('+1 day')` → `Clock::now('+1 day')->getTimestamp()`; `date_create_immutable()` → `Clock::now()`;
 * - `sleep($s)` → `Clock::sleep($s)`; `usleep($us)` → `Clock::sleep($us / 1_000_000)`;
 * - `Symfony\Component\Clock\now(…)` → `Clock::now(…)`.
 *
 * What is left — `new \DateTime()`, `gmdate()`, `idate()`, `mktime()`, an incomplete format, and a `new` in a constant
 * expression (a default value, an attribute argument, a static variable, a constant), where no call may go — the PHPStan
 * rule reports, and is done by hand. Run it on the code, not on the tests.
 *
 * ```php
 * return RectorConfig::configure()->withConfiguredRule(UseClockRector::class, ['clock' => 'App\Clock']);
 * ```
 */
final class UseClockRector extends AbstractRector implements ConfigurableRectorInterface
{
    /** Set on the nodes of a constant expression, which may hold a `new` but no call. */
    private const string IN_CONSTANT_EXPRESSION = 'devtools_clock_in_constant_expression';

    private string $clock = TimeReading::DEFAULT_CLOCK;

    /**
     * @param array<mixed> $configuration `['clock' => 'Vendor\Clock']`, the class that reads the time
     */
    #[\Override]
    public function configure(array $configuration): void
    {
        $clock = $configuration['clock'] ?? TimeReading::DEFAULT_CLOCK;

        if (!\is_string($clock) || '' === $clock) {
            throw new \InvalidArgumentException('The "clock" of UseClockRector is the name of a class.');
        }

        $this->clock = $clock;
    }

    /**
     * @return list<class-string<Node>>
     */
    #[\Override]
    public function getNodeTypes(): array
    {
        return [Param::class, Attribute::class, StaticVar::class, Const_::class, New_::class, FuncCall::class];
    }

    /**
     * @param Param|Attribute|StaticVar|Const_|New_|FuncCall $node
     */
    #[\Override]
    public function refactor(Node $node): ?Node
    {
        // A constant expression is entered before what it holds: its readings are marked, and left.
        if (!$node instanceof New_ && !$node instanceof FuncCall) {
            $this->traverseNodesWithCallable($node, static function (Node $inner): null {
                $inner->setAttribute(self::IN_CONSTANT_EXPRESSION, true);

                return null;
            });

            return null;
        }

        if (true === $node->getAttribute(self::IN_CONSTANT_EXPRESSION)) {
            return null;
        }

        $args = $this->plainArgs($node);

        if (null === $args) {
            return null;
        }

        if ($node instanceof New_) {
            return $this->isName($node->class, 'DateTimeImmutable') ? $this->construction($args) : null;
        }

        return match ($this->getName($node)) {
            'time' => [] === $args ? $this->clock('timestamp') : null,
            'date' => 1 === \count($args) ? new MethodCall($this->clock('now'), 'format', $args) : null,
            'strtotime' => 1 === \count($args) && $args[0]->value instanceof String_ && TimeReading::isRelative($args[0]->value)
                ? new MethodCall($this->clock('now', $args), 'getTimestamp')
                : null,
            'date_create_immutable' => [] === $args ? $this->clock('now') : null,
            'sleep' => 1 === \count($args) ? $this->clock('sleep', $args) : null,
            'usleep' => 1 === \count($args) ? $this->clock('sleep', [new Arg(new Div($args[0]->value, new Int_(1_000_000, ['rawValue' => '1_000_000', 'shouldPrintRawValue' => true])))]) : null,
            'Symfony\Component\Clock\now' => $this->clock('now', $args),
            default => null,
        };
    }

    /**
     * @param list<Arg> $args
     */
    private function construction(array $args): ?StaticCall
    {
        if (\count($args) > 2) {
            return null;
        }

        $first = $args[0]->value ?? null;
        $constants = $first instanceof Expr ? array_map(static fn (ConstantStringType $string): string => $string->getValue(), $this->getType($first)->getConstantStrings()) : [];

        if (!TimeReading::isRelative($first, $constants)) {
            return null;
        }

        // 'now' alone is the default: Clock::now() reads as new \DateTimeImmutable() did.
        if (1 === \count($args) && $first instanceof String_ && 'now' === strtolower(trim($first->value))) {
            $args = [];
        }

        return $this->clock('now', $args);
    }

    /**
     * @param list<Arg> $args
     */
    private function clock(string $method, array $args = []): StaticCall
    {
        return new StaticCall(new FullyQualified($this->clock), $method, $args);
    }

    /**
     * The arguments, when none is named, unpacked or a placeholder: those calls are left alone.
     *
     * @return list<Arg>|null
     */
    private function plainArgs(New_|FuncCall $node): ?array
    {
        if ($node->isFirstClassCallable() || ($node instanceof New_ && !$node->class instanceof Name) || ($node instanceof FuncCall && !$node->name instanceof Name)) {
            return null;
        }

        $args = [];

        foreach ($node->args as $arg) {
            if (!$arg instanceof Arg || $arg->name instanceof Identifier || $arg->unpack) {
                return null;
            }

            $args[] = $arg;
        }

        return $args;
    }
}
