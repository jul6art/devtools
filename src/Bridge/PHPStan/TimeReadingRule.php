<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Bridge\PHPStan;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\Constant\ConstantStringType;

/**
 * A reading of the time that does not go through the project's clock, reported at its line (ADR-0055).
 *
 * Nothing is decided here: {@see TimeReading} judges the call. Included by `resources/phpstan/clock.neon`, apart
 * from the other rules.
 *
 * @implements Rule<CallLike>
 */
class TimeReadingRule implements Rule
{
    public function __construct(
        private readonly TimeReading $reading = new TimeReading(),
    ) {
    }

    /**
     * @param list<string> $allowed
     * @param list<string> $tests
     */
    public static function create(string $clock = TimeReading::DEFAULT_CLOCK, array $allowed = TimeReading::DEFAULT_ALLOWED, array $tests = TimeReading::DEFAULT_TESTS): self
    {
        return new self(new TimeReading($clock, $allowed, $tests));
    }

    #[\Override]
    public function getNodeType(): string
    {
        return CallLike::class;
    }

    #[\Override]
    public function processNode(Node $node, Scope $scope): array
    {
        if ($node->isFirstClassCallable() || $this->reading->allows($scope->getClassReflection()?->getName(), $scope->getTraitReflection()?->getName())) {
            return [];
        }

        $args = array_values(array_filter($node->getRawArgs(), static fn (mixed $arg): bool => $arg instanceof Arg));
        $file = $scope->getFile();

        $verdict = match (true) {
            $node instanceof New_ && $node->class instanceof Name => $this->reading->ofConstruction($scope->resolveName($node->class), $args, self::constants($args, $scope), $file),
            $node instanceof FuncCall && $node->name instanceof Name => $this->reading->ofFunction($node->name->toString(), $args, self::constants($args, $scope), $file),
            $node instanceof StaticCall && $node->class instanceof Name && $node->name instanceof Identifier => $this->reading->ofStaticCall($scope->resolveName($node->class), $node->name->toString(), $args, $file),
            default => null,
        };

        if (null === $verdict) {
            return [];
        }

        return [
            RuleErrorBuilder::message($verdict['message'])
                ->identifier('devtools.clock')
                ->line($node->getStartLine())
                ->tip($verdict['tip'])
                ->build(),
        ];
    }

    /**
     * The values PHPStan knows the first argument can take — none when it does not know them all.
     *
     * @param list<Arg> $args
     *
     * @return list<string>
     */
    private static function constants(array $args, Scope $scope): array
    {
        if (!isset($args[0])) {
            return [];
        }

        return array_map(static fn (ConstantStringType $string): string => $string->getValue(), $scope->getType($args[0]->value)->getConstantStrings());
    }
}
