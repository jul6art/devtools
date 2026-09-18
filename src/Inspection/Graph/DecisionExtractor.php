<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Inspection\Graph;

use Jul6Art\DevTools\Inspection\Model\Confidence;
use Jul6Art\DevTools\Inspection\Model\DecisionPoint;
use Jul6Art\DevTools\Inspection\Model\FileRef;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\BinaryOp\Coalesce;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\Match_;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\Ternary;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\FunctionLike;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Else_;
use PhpParser\Node\Stmt\If_;
use PhpParser\Node\Stmt\Property;
use PhpParser\Node\Stmt\Return_;
use PhpParser\NodeFinder;
use PhpParser\PrettyPrinter\Standard;

/**
 * Finds, in code already parsed for the dependency graph, the places where a field is given one value
 * rather than another (ADR-0043).
 *
 * It reads the syntax trees {@see PhpReferenceExtractor} keeps for the scan, so a workflow of eighty
 * files costs no extra parse, and a file shared by twenty workflows is read once. Nothing is
 * interpreted and nothing is executed: the condition and the value come out as they are written, and
 * turning them into business language is Claude's job (ADR-0043).
 *
 * Two bounds keep a thousand-line controller from drowning the model, and the caller is told when one
 * of them was reached: three nested conditions, and a maximum number of points per file.
 */
final class DecisionExtractor
{
    /**
     * Past this depth a branch answers "why" less and less, and reads worse and worse.
     */
    public const int MAX_DEPTH = 3;

    /**
     * Per file read. The builder applies the same bound again to the workflow as a whole.
     */
    public const int MAX_POINTS = 50;

    /**
     * Decided fields kept per workflow. A route of a real back-office reaches a dozen entities, each with
     * its own conditional setters; a page carrying a dozen diagrams is the inventory again. The richest
     * logic is kept — the fields with the most branches — and the report says when the rest was dropped.
     */
    public const int MAX_TARGETS = 8;

    private readonly Standard $printer;

    /**
     * @var array<string, list<DecisionPoint>> path and scope => what was found there
     */
    private array $cache = [];

    /**
     * @var list<DecisionPoint>
     */
    private array $points = [];

    /**
     * @var array<string, true> the decisions already recorded for the file being read
     */
    private array $seen = [];

    /**
     * @var array<string, string> variable name => fully qualified type, within the scope being read
     */
    private array $types = [];

    private string $scope = '';

    private string $method = '';

    private bool $truncated = false;

    public function __construct(private readonly PhpReferenceExtractor $extractor)
    {
        $this->printer = new Standard();
    }

    /**
     * @param list<string> $methods the methods to read; empty reads the whole file
     *
     * @return list<DecisionPoint>
     */
    public function extract(string $absolutePath, FileRef $file, array $methods = []): array
    {
        $key = $absolutePath."\0".implode(',', $methods);

        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }

        $statements = $this->extractor->syntaxTree($absolutePath);

        if (\is_string($statements)) {
            return $this->cache[$key] = [];
        }

        $this->points = [];
        $this->seen = [];
        $this->types = [];
        $this->scope = '';
        $this->method = '';
        $this->walk($this->inScope($statements, $methods), $file, [], 0);

        return $this->cache[$key] = $this->points;
    }

    /**
     * True when a bound stopped the reading: the report says so rather than pretending the model is whole.
     */
    public function truncated(): bool
    {
        return $this->truncated;
    }

    /**
     * The whole file, or only the named methods of its class — the same scoping the dependency graph uses,
     * so that the list route does not inherit the decisions of the creation route.
     *
     * @param list<Node>   $statements
     * @param list<string> $methods
     *
     * @return list<Node>
     */
    private function inScope(array $statements, array $methods): array
    {
        if ([] === $methods) {
            return $statements;
        }

        $class = new NodeFinder()->findFirstInstanceOf($statements, ClassLike::class);

        if (!$class instanceof ClassLike) {
            return $statements;
        }

        $this->scope = $class->namespacedName?->toString() ?? $class->name?->toString() ?? '';
        $kept = [];

        foreach ($class->stmts as $member) {
            if (!$member instanceof ClassMethod || '__construct' === $member->name->toString() || \in_array($member->name->toString(), $methods, true)) {
                $kept[] = $member;
            }
        }

        return $kept;
    }

    /**
     * @param array<Node|null>  $nodes
     * @param list<string|null> $conditions the branch currently being read, outermost first
     */
    private function walk(array $nodes, FileRef $file, array $conditions, int $depth): void
    {
        foreach ($nodes as $node) {
            if ($node instanceof Node) {
                $this->walkOne($node, $file, $conditions, $depth);
            }
        }
    }

    /**
     * @param list<string|null> $conditions
     */
    private function walkOne(Node $node, FileRef $file, array $conditions, int $depth): void
    {
        if (\count($this->points) >= self::MAX_POINTS) {
            $this->truncated = true;

            return;
        }

        if ($node instanceof ClassLike) {
            $this->scope = $node->namespacedName?->toString() ?? $node->name?->toString() ?? $this->scope;
        }

        if ($node instanceof ClassMethod) {
            $this->method = $node->name->toString();
        }

        if ($node instanceof FunctionLike) {
            $this->learnParameterTypes($node);
        }

        if ($node instanceof Property) {
            $this->learnPropertyTypes($node);
        }

        if ($node instanceof Assign && $node->var instanceof Variable && \is_string($node->var->name) && $node->expr instanceof New_ && $node->expr->class instanceof Name) {
            $this->types[$node->var->name] = $node->expr->class->toString();
        }

        if ($node instanceof If_) {
            $this->branches($node, $file, $conditions, $depth);

            return;
        }

        if ($node instanceof Match_) {
            $this->arms($node, $file, $conditions, $depth);

            return;
        }

        if ($node instanceof Ternary) {
            $condition = $this->print($node->cond);
            $this->branch([$node->if], $file, $conditions, $condition, $depth);
            $this->branch([$node->else], $file, $conditions, self::negate($condition), $depth);

            return;
        }

        $this->record($node, $file, $conditions, $depth);

        foreach ($node->getSubNodeNames() as $name) {
            $child = $node->{$name};
            $children = \is_array($child) ? $child : [$child];
            $this->walk(array_values(array_filter($children, static fn (mixed $value): bool => $value instanceof Node)), $file, $conditions, $depth);
        }
    }

    /**
     * @param list<string|null> $conditions
     */
    private function branches(If_ $node, FileRef $file, array $conditions, int $depth): void
    {
        $taken = [$this->print($node->cond)];
        $this->branch($node->stmts, $file, $conditions, $taken[0], $depth);

        foreach ($node->elseifs as $elseif) {
            $condition = $this->print($elseif->cond);
            $this->branch($elseif->stmts, $file, $conditions, $condition, $depth);
            $taken[] = $condition;
        }

        if ($node->else instanceof Else_) {
            $this->branch($node->else->stmts, $file, $conditions, self::negate(implode(' || ', $taken)), $depth);
        }
    }

    /**
     * @param list<string|null> $conditions
     */
    private function arms(Match_ $node, FileRef $file, array $conditions, int $depth): void
    {
        $subject = $this->print($node->cond);

        foreach ($node->arms as $arm) {
            $condition = null === $arm->conds
                ? $subject.' — default'
                : implode(' || ', array_map(fn (Expr $value): string => $subject.' === '.$this->print($value), $arm->conds));

            $this->branch([$arm->body], $file, $conditions, $condition, $depth);
        }
    }

    /**
     * @param array<Node|null>  $nodes
     * @param list<string|null> $conditions
     */
    private function branch(array $nodes, FileRef $file, array $conditions, string $condition, int $depth): void
    {
        if ($depth >= self::MAX_DEPTH) {
            $this->truncated = true;

            return;
        }

        $this->walk($nodes, $file, [...$conditions, $condition], $depth + 1);
    }

    /**
     * The three shapes a decided value takes: a setter, a property written directly, and a method that
     * returns a constant or an enumeration case.
     *
     * @param list<string|null> $conditions
     */
    private function record(Node $node, FileRef $file, array $conditions, int $depth): void
    {
        [$target, $value, $confidence] = match (true) {
            $node instanceof MethodCall => $this->setterCall($node),
            $node instanceof Assign => $this->propertyAssignment($node),
            $node instanceof Return_ && $node->expr instanceof ClassConstFetch => [$this->returnTarget(), $node->expr, Confidence::High],
            default => [null, null, Confidence::High],
        };

        if (null === $target || !$value instanceof Expr) {
            return;
        }

        $this->recordValue($target, $value, $file, $conditions, $confidence, $depth);
    }

    /**
     * The value of one target, following the branches **inside** the value itself: `setStatus(match (…))`
     * decides three values, not one expression nobody can read.
     *
     * @param list<string|null> $conditions
     */
    private function recordValue(string $target, Expr $value, FileRef $file, array $conditions, Confidence $confidence, int $depth): void
    {
        if ($depth < self::MAX_DEPTH && $value instanceof Match_) {
            $subject = $this->print($value->cond);

            foreach ($value->arms as $arm) {
                $condition = null === $arm->conds
                    ? $subject.' — default'
                    : implode(' || ', array_map(fn (Expr $case): string => $subject.' === '.$this->print($case), $arm->conds));

                $this->recordValue($target, $arm->body, $file, [...$conditions, $condition], $confidence, $depth + 1);
            }

            return;
        }

        if ($depth < self::MAX_DEPTH && $value instanceof Ternary) {
            $condition = $this->print($value->cond);
            $this->recordValue($target, $value->if ?? $value->cond, $file, [...$conditions, $condition], $confidence, $depth + 1);
            $this->recordValue($target, $value->else, $file, [...$conditions, self::negate($condition)], $confidence, $depth + 1);

            return;
        }

        if ($depth < self::MAX_DEPTH && $value instanceof Coalesce) {
            $left = $this->print($value->left);
            $this->recordValue($target, $value->left, $file, [...$conditions, self::negate('null === '.$left)], $confidence, $depth + 1);
            $this->recordValue($target, $value->right, $file, [...$conditions, 'null === '.$left], $confidence, $depth + 1);

            return;
        }

        $point = new DecisionPoint(
            target: $target,
            value: $this->print($value),
            condition: [] === $conditions ? null : implode(' && ', array_filter($conditions)),
            declaredIn: $file,
            line: max(1, $value->getStartLine()),
            confidence: $confidence,
        );

        // The same decision written twice on one line is one decision: a duplicate key would otherwise
        // stop the whole inspection when the model is built.
        if (!isset($this->seen[$point->sortKey()])) {
            $this->seen[$point->sortKey()] = true;
            $this->points[] = $point;
        }
    }

    /**
     * What a method decides is its own return: `App\Service\VatSuggester::suggest`.
     */
    private function returnTarget(): ?string
    {
        return '' === $this->scope || '' === $this->method ? null : $this->scope.'::'.$this->method;
    }

    /**
     * @return array{string|null, Expr|null, Confidence}
     */
    private function setterCall(MethodCall $node): array
    {
        $name = $node->name instanceof Identifier ? $node->name->toString() : null;
        $argument = $node->args[0] ?? null;

        if (null === $name || 1 !== preg_match('/^set[A-Z]/', $name) || 1 !== \count($node->args) || !$argument instanceof Arg) {
            return [null, null, Confidence::High];
        }

        [$receiver, $confidence] = $this->receiver($node->var);

        return [$receiver.'::'.lcfirst(substr($name, 3)), $argument->value, $confidence];
    }

    /**
     * @return array{string|null, Expr|null, Confidence}
     */
    private function propertyAssignment(Assign $node): array
    {
        $fetch = $node->var;

        if (!$fetch instanceof PropertyFetch || !$fetch->name instanceof Identifier) {
            return [null, null, Confidence::High];
        }

        [$receiver, $confidence] = $this->receiver($fetch->var);

        return [$receiver.'::'.$fetch->name->toString(), $node->expr, $confidence];
    }

    /**
     * The type of what is being written to, when it can be known without inferring anything: a typed
     * parameter, a typed property, `new X`, `$this`. Otherwise the name as written, at lower confidence —
     * named, never invented, never silently dropped.
     *
     * @return array{string, Confidence}
     */
    private function receiver(Node $node): array
    {
        // A fluent chain writes on the object it STARTED from: `(new RolePermission())->setRoleCode(…)
        // ->setPermission(…)` decides `RolePermission::permission`. Without walking it up, the target was
        // the printed chain itself — unreadable as a field name, and impossible to quote back in a page,
        // since a target is named as one word (ADR-0043).
        if ($node instanceof MethodCall && $node->name instanceof Identifier && self::isFluent($node->name->toString())) {
            return $this->receiver($node->var);
        }

        if ($node instanceof Variable && \is_string($node->name)) {
            if ('this' === $node->name) {
                return '' === $this->scope ? ['this', Confidence::Medium] : [$this->scope, Confidence::High];
            }

            $type = $this->types[$node->name] ?? null;

            return null === $type ? [$node->name, Confidence::Medium] : [$type, Confidence::High];
        }

        if ($node instanceof PropertyFetch && $node->name instanceof Identifier) {
            $type = $this->types['$this->'.$node->name->toString()] ?? null;

            return null === $type ? [$node->name->toString(), Confidence::Medium] : [$type, Confidence::High];
        }

        if ($node instanceof New_ && $node->class instanceof Name) {
            return [$node->class->toString(), Confidence::High];
        }

        $printed = trim($node instanceof Expr ? $this->print($node) : 'unknown', '$');

        // Whatever is left is named, never dropped — but never with a space in it: a page quotes a target
        // as one word, so a printed expression carrying spaces would name a field no author can write.
        return [(string) preg_replace('/\s+/', '', $printed), Confidence::Medium];
    }

    /**
     * The method names that conventionally return the object they were called on. Only those are walked
     * up: `$repository->find($id)->setName(…)` starts from the REPOSITORY, and following it there would
     * name the wrong type with the confidence of a certainty.
     */
    private static function isFluent(string $method): bool
    {
        return 1 === preg_match('/^(set|add|remove|with)[A-Z]/', $method);
    }

    private function learnParameterTypes(FunctionLike $node): void
    {
        foreach ($node->getParams() as $parameter) {
            if ($parameter->var instanceof Variable && \is_string($parameter->var->name) && $parameter->type instanceof Name) {
                $this->types[$parameter->var->name] = $parameter->type->toString();
            }

            // Constructor property promotion: the parameter is also the property.
            if (0 !== $parameter->flags && $parameter->var instanceof Variable && \is_string($parameter->var->name) && $parameter->type instanceof Name) {
                $this->types['$this->'.$parameter->var->name] = $parameter->type->toString();
            }
        }
    }

    private function learnPropertyTypes(Property $node): void
    {
        if (!$node->type instanceof Name) {
            return;
        }

        foreach ($node->props as $property) {
            $this->types['$this->'.$property->name->toString()] = $node->type->toString();
        }
    }

    private function print(Expr $node): string
    {
        return trim(str_replace(["\n", "\r"], ' ', $this->printer->prettyPrintExpr($node)));
    }

    private static function negate(string $condition): string
    {
        return '!('.$condition.')';
    }
}
