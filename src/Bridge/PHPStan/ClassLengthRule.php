<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Bridge\PHPStan;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\InClassNode;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * A class-like declaration longer than the project accepts, reported at its line (ADR-0054).
 *
 * Nothing is decided here: {@see ClassLength} measures and words it. Included by
 * `resources/phpstan/class-length.neon`, apart from the drift rule.
 *
 * @implements Rule<InClassNode>
 */
class ClassLengthRule implements Rule
{
    public function __construct(
        private readonly ClassLength $length = new ClassLength(),
    ) {
    }

    /**
     * @param list<string> $exclude
     */
    public static function create(int $max = ClassLength::DEFAULT_MAX, array $exclude = ClassLength::DEFAULT_EXCLUDE): self
    {
        return new self(new ClassLength($max, $exclude));
    }

    #[\Override]
    public function getNodeType(): string
    {
        return InClassNode::class;
    }

    #[\Override]
    public function processNode(Node $node, Scope $scope): array
    {
        $reflection = $node->getClassReflection();

        // An anonymous class lives in another one, which is the one measured.
        if ($reflection->isAnonymous()) {
            return [];
        }

        $declaration = $node->getOriginalNode();
        $firstLine = $declaration->getDocComment()?->getStartLine() ?? $declaration->getStartLine();

        $verdict = $this->length->of($reflection->getName(), $scope->getFile(), $firstLine, $declaration->getEndLine());

        if (null === $verdict) {
            return [];
        }

        return [
            RuleErrorBuilder::message($verdict['message'])
                ->identifier('devtools.classLength')
                ->line($declaration->getStartLine())
                ->tip($verdict['tip'])
                ->build(),
        ];
    }
}
