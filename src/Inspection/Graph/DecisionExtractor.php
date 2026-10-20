<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Inspection\Graph;

use Jul6Art\DevTools\Inspection\Model\Confidence;
use Jul6Art\DevTools\Inspection\Model\DecisionPoint;
use Jul6Art\DevTools\Inspection\Model\FileRef;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\ArrayItem;
use PhpParser\Node\Attribute;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\BinaryOp\Coalesce;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\Match_;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Ternary;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\FunctionLike;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Param;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Else_;
use PhpParser\Node\Stmt\ElseIf_;
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
 *
 * ADR-0044 widens what it reads, never how deep: the conditions of a method of the same class whose
 * result is the decided value (one call, never two), the validation constraints of a property, the
 * options of a form field, and the votes of a voter.
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

    /**
     * How many calls a decided value is followed into (ADR-0044): `setStatus($this->decide($order))` reads
     * `decide()`. At the third place in the code a condition no longer reads, so a second call is recorded
     * as written.
     */
    public const int MAX_CALL_DEPTH = 1;

    // Class names of the analysed project, written as strings on purpose: DevTools does not depend on these
    // packages, and a ::class constant would make that look otherwise.
    private const string CHOICE = 'Symfony\Component\Validator\Constraints\Choice';

    private const string EXPRESSION = 'Symfony\Component\Validator\Constraints\Expression';

    private const string WHEN = 'Symfony\Component\Validator\Constraints\When';

    /**
     * The options of a form field that decide the value of the property behind it, and those that decide
     * the field itself.
     */
    private const array VALUE_OPTIONS = ['choices', 'query_builder'];

    private const array FIELD_OPTIONS = ['required', 'disabled'];

    /**
     * What a voter decides: access, granted or denied (ADR-0044).
     */
    public const string ACCESS = 'access';

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

    /**
     * The class being read, whole even when only some of its methods are: a call goes where it goes.
     */
    private ?ClassLike $class = null;

    /**
     * The `data_class` of the form being read: the entity whose properties its fields decide.
     */
    private ?string $dataClass = null;

    private int $callDepth = 0;

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

        $this->start($statements);
        $this->walk($this->inScope($statements, $methods), $file, [], 0);

        return $this->cache[$key] = $this->points;
    }

    /**
     * What a voter decides in `voteOnAttribute()`: access granted or denied, under the condition written
     * (ADR-0044). A vote is not the value of a field, so the general reading does not find it.
     *
     * @return list<DecisionPoint>
     */
    public function votes(string $absolutePath, FileRef $file): array
    {
        $key = $absolutePath."\0votes";

        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }

        $statements = $this->extractor->syntaxTree($absolutePath);

        if (\is_string($statements)) {
            return $this->cache[$key] = [];
        }

        $this->start($statements);
        $method = $this->class?->getMethod('voteOnAttribute');

        if (!$method instanceof ClassMethod) {
            return $this->cache[$key] = [];
        }

        $this->scope = $this->class?->namespacedName?->toString() ?? '';

        foreach ($this->returnsOf($method->stmts ?? [], [], 0) as [$returned, $conditions]) {
            foreach ($this->leaves($returned, $conditions, 0) as [$leaf, $leafConditions]) {
                $this->vote($leaf, [], $leafConditions, null, $file);
            }
        }

        return $this->cache[$key] = $this->points;
    }

    /**
     * @param list<Node> $statements
     */
    private function start(array $statements): void
    {
        $this->points = [];
        $this->seen = [];
        $this->types = [];
        $this->scope = '';
        $this->method = '';
        $this->callDepth = 0;
        $this->class = new NodeFinder()->findFirstInstanceOf($statements, ClassLike::class);
        $this->dataClass = null;

        // `'data_class' => Customer::class`: the entity the form's fields write to.
        foreach (new NodeFinder()->findInstanceOf($statements, ArrayItem::class) as $item) {
            if ($item->key instanceof String_ && 'data_class' === $item->key->value && $item->value instanceof ClassConstFetch && $item->value->class instanceof Name) {
                $this->dataClass = $item->value->class->toString();
            }
        }
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
            $this->class = $node;
        }

        if ($node instanceof ClassMethod) {
            $this->method = $node->name->toString();
        }

        if ($node instanceof FunctionLike) {
            $this->learnParameterTypes($node);
        }

        if ($node instanceof Property) {
            $this->learnPropertyTypes($node);
            $this->constraints($node, $file);
        }

        if ($node instanceof MethodCall && $node->name instanceof Identifier && 'add' === $node->name->toString()) {
            $this->formField($node, $file, $conditions, $depth);
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

        // ADR-0044 § 4: the value is the result of a method of this class — its conditions are the decision.
        $called = $this->callDepth < self::MAX_CALL_DEPTH ? $this->calledMethod($value) : null;

        if ($called instanceof ClassMethod) {
            $leaves = $this->leavesOf($called);

            if ([] !== $leaves) {
                ++$this->callDepth;

                foreach ($leaves as [$leaf, $inner]) {
                    $this->recordValue($target, $leaf, $file, self::compose($conditions, $called, $inner), $confidence, $depth);
                }

                --$this->callDepth;

                return;
            }
        }

        $this->add($target, $this->print($value), $conditions, $file, $value->getStartLine(), $confidence);
    }

    /**
     * @param list<string|null> $conditions
     */
    private function add(string $target, string $value, array $conditions, FileRef $file, int $line, Confidence $confidence): void
    {
        $point = new DecisionPoint(
            target: $target,
            value: $value,
            condition: [] === array_filter($conditions) ? null : implode(' && ', array_filter($conditions)),
            declaredIn: $file,
            line: max(1, $line),
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
     * A vote: `true` grants, `false` denies, and anything else grants under itself and denies under its
     * opposite. A call to a method of the voter is followed once, like a decided value.
     *
     * @param list<string|null> $outer the conditions of the vote itself
     * @param list<string>      $inner the conditions inside the called method, when there is one
     */
    private function vote(Expr $leaf, array $outer, array $inner, ?ClassMethod $in, FileRef $file): void
    {
        if ($leaf instanceof ConstFetch && \in_array(strtolower($leaf->name->toString()), ['true', 'false'], true)) {
            $this->add(self::ACCESS, 'true' === strtolower($leaf->name->toString()) ? 'granted' : 'denied', self::compose($outer, $in, $inner), $file, $leaf->getStartLine(), Confidence::High);

            return;
        }

        $called = !$in instanceof ClassMethod && $this->callDepth < self::MAX_CALL_DEPTH ? $this->calledMethod($leaf) : null;

        if ($called instanceof ClassMethod && [] !== $this->leavesOf($called)) {
            ++$this->callDepth;

            foreach ($this->leavesOf($called) as [$calledLeaf, $calledConditions]) {
                $this->vote($calledLeaf, self::compose($outer, null, $inner), $calledConditions, $called, $file);
            }

            --$this->callDepth;

            return;
        }

        $condition = $this->print($leaf);
        $this->add(self::ACCESS, 'granted', self::compose($outer, $in, [...$inner, $condition]), $file, $leaf->getStartLine(), Confidence::High);
        $this->add(self::ACCESS, 'denied', self::compose($outer, $in, [...$inner, self::negate($condition)]), $file, $leaf->getStartLine(), Confidence::High);
    }

    /**
     * `$this->decide(…)`, `self::decide(…)`, `static::decide(…)`: a method of the class being read, with a
     * body. Anything else — a method of another object — is recorded as written.
     */
    private function calledMethod(Expr $value): ?ClassMethod
    {
        $name = match (true) {
            $value instanceof MethodCall && $value->var instanceof Variable && 'this' === $value->var->name && $value->name instanceof Identifier => $value->name->toString(),
            $value instanceof StaticCall && $value->class instanceof Name && \in_array(strtolower($value->class->toString()), ['self', 'static'], true) && $value->name instanceof Identifier => $value->name->toString(),
            default => null,
        };

        $method = null === $name ? null : $this->class?->getMethod($name);

        return null !== $method?->stmts ? $method : null;
    }

    /**
     * Every value a method can return, with the conditions under which it does — inside the method.
     *
     * @return list<array{Expr, list<string>}>
     */
    private function leavesOf(ClassMethod $method): array
    {
        $leaves = [];

        foreach ($this->returnsOf($method->stmts ?? [], [], 0) as [$returned, $conditions]) {
            foreach ($this->leaves($returned, $conditions, 0) as $leaf) {
                $leaves[] = $leaf;
            }
        }

        return $leaves;
    }

    /**
     * The returns of a block, in order. A branch that returns makes what follows it conditional on its
     * opposite: `if ($a) { return 1; } return 2;` returns 2 under `!($a)`.
     *
     * @param array<Node\Stmt> $statements
     * @param list<string>     $conditions
     *
     * @return list<array{Expr, list<string>}>
     */
    private function returnsOf(array $statements, array $conditions, int $depth): array
    {
        $found = [];

        foreach ($statements as $statement) {
            if ($statement instanceof Return_) {
                return $statement->expr instanceof Expr ? [...$found, [$statement->expr, $conditions]] : $found;
            }

            if (!$statement instanceof If_) {
                continue;
            }

            if ($depth >= self::MAX_DEPTH) {
                $this->truncated = true;

                continue;
            }

            $taken = [];
            $allReturn = true;

            foreach ([[$statement->cond, $statement->stmts], ...array_map(static fn (ElseIf_ $elseif): array => [$elseif->cond, $elseif->stmts], $statement->elseifs)] as [$condition, $body]) {
                $printed = $this->print($condition);
                $found = [...$found, ...$this->returnsOf($body, [...$conditions, $printed], $depth + 1)];
                $taken[] = $printed;
                $allReturn = $allReturn && self::returns($body);
            }

            $otherwise = self::negate(implode(' || ', $taken));

            if ($statement->else instanceof Else_) {
                $found = [...$found, ...$this->returnsOf($statement->else->stmts, [...$conditions, $otherwise], $depth + 1)];

                if ($allReturn && self::returns($statement->else->stmts)) {
                    return $found;
                }
            } elseif ($allReturn) {
                $conditions[] = $otherwise;
            }
        }

        return $found;
    }

    /**
     * @param array<Node\Stmt> $statements
     */
    private static function returns(array $statements): bool
    {
        return array_last($statements) instanceof Return_;
    }

    /**
     * A returned value split along the branches written inside it, as {@see recordValue()} splits a
     * decided one.
     *
     * @param list<string> $conditions
     *
     * @return list<array{Expr, list<string>}>
     */
    private function leaves(Expr $value, array $conditions, int $depth): array
    {
        if ($depth >= self::MAX_DEPTH) {
            return [[$value, $conditions]];
        }

        if ($value instanceof Match_) {
            $subject = $this->print($value->cond);
            $leaves = [];

            foreach ($value->arms as $arm) {
                $condition = null === $arm->conds
                    ? $subject.' — default'
                    : implode(' || ', array_map(fn (Expr $case): string => $subject.' === '.$this->print($case), $arm->conds));
                $leaves = [...$leaves, ...$this->leaves($arm->body, [...$conditions, $condition], $depth + 1)];
            }

            return $leaves;
        }

        if ($value instanceof Ternary) {
            $condition = $this->print($value->cond);

            return [
                ...$this->leaves($value->if ?? $value->cond, [...$conditions, $condition], $depth + 1),
                ...$this->leaves($value->else, [...$conditions, self::negate($condition)], $depth + 1),
            ];
        }

        return [[$value, $conditions]];
    }

    /**
     * The conditions of a point found inside a called method: what led to the call, then what the method
     * itself tests, prefixed with its name — `decide() : null === $vatNumber` — so that a reader knows the
     * condition is written somewhere else.
     *
     * @param list<string|null> $outer
     * @param list<string>      $inner
     *
     * @return list<string|null>
     */
    private static function compose(array $outer, ?ClassMethod $in, array $inner): array
    {
        if ([] === $inner) {
            return $outer;
        }

        return [...$outer, $in instanceof ClassMethod ? $in->name->toString().'() : '.implode(' && ', $inner) : implode(' && ', $inner)];
    }

    /**
     * ADR-0044 § 3: `#[Assert\Choice]`, `#[Assert\Expression]` and `#[Assert\When]` decide the values a
     * property may hold before it reaches the database.
     */
    private function constraints(Property $node, FileRef $file): void
    {
        if ('' === $this->scope) {
            return;
        }

        foreach ($node->props as $property) {
            $target = $this->scope.'::'.$property->name->toString();

            foreach ($node->attrGroups as $group) {
                foreach ($group->attrs as $attribute) {
                    $this->constraint($attribute, $target, $file);
                }
            }
        }
    }

    private function constraint(Attribute $attribute, string $target, FileRef $file): void
    {
        $line = $attribute->getStartLine();

        switch ($attribute->name->toString()) {
            case self::CHOICE:
                $groups = AttributeArgument::of($attribute, -1, 'groups');
                $condition = $groups instanceof Array_ ? 'groups: '.implode(', ', array_map(static fn (ArrayItem $item): string => $item->value instanceof String_ ? $item->value->value : '?', $groups->items)) : null;
                $choices = AttributeArgument::of($attribute, 0, 'choices');
                $callback = AttributeArgument::of($attribute, -1, 'callback');

                foreach ($choices instanceof Array_ ? $choices->items : [] as $item) {
                    $this->add($target, $this->print($item->value), [$condition], $file, $line, Confidence::High);
                }

                if ($callback instanceof Expr) {
                    $this->add($target, 'callback: '.$this->print($callback), [$condition], $file, $line, Confidence::High);
                }

                return;

            case self::EXPRESSION:
                $expression = AttributeArgument::of($attribute, 0, 'expression');

                if ($expression instanceof String_) {
                    $this->add($target, 'valid', [$expression->value], $file, $line, Confidence::High);
                    $this->add($target, 'invalid', [self::negate($expression->value)], $file, $line, Confidence::High);
                }

                return;

            case self::WHEN:
                $expression = AttributeArgument::of($attribute, 0, 'expression');
                $constraints = AttributeArgument::of($attribute, 1, 'constraints');
                $otherwise = AttributeArgument::of($attribute, 2, 'otherwise');

                if ($expression instanceof String_ && $constraints instanceof Expr) {
                    $this->add($target, $this->describeConstraints($constraints), [$expression->value], $file, $line, Confidence::High);
                }

                if ($expression instanceof String_ && $otherwise instanceof Expr) {
                    $this->add($target, $this->describeConstraints($otherwise), [self::negate($expression->value)], $file, $line, Confidence::High);
                }

                return;
        }
    }

    /**
     * `[new Assert\NotBlank(), new Assert\Length(max: 12)]` → `NotBlank, Length(max: 12)`: what a reader
     * needs, without the namespace the resolved names would print.
     */
    private function describeConstraints(Expr $constraints): string
    {
        $items = $constraints instanceof Array_ ? array_map(static fn (ArrayItem $item): Expr => $item->value, $constraints->items) : [$constraints];

        return implode(', ', array_map(function (Expr $constraint): string {
            if (!$constraint instanceof New_ || !$constraint->class instanceof Name) {
                return $this->print($constraint);
            }

            $arguments = array_map(fn (Node $argument): string => $argument instanceof Arg ? ($argument->name instanceof Identifier ? $argument->name->toString().': ' : '').$this->print($argument->value) : '', $constraint->args);

            return $constraint->class->getLast().([] === $arguments ? '' : '('.implode(', ', $arguments).')');
        }, $items));
    }

    /**
     * ADR-0044 § 3: `->add('site', EntityType::class, ['choices' => …])` decides the values the property
     * behind the field may take — on the entity of the form's `data_class` — and `required` or `disabled`
     * under a condition decides the field itself.
     *
     * @param list<string|null> $conditions
     */
    private function formField(MethodCall $node, FileRef $file, array $conditions, int $depth): void
    {
        $name = $node->args[0] ?? null;
        $options = $node->args[2] ?? null;

        if (!$name instanceof Arg || !$name->value instanceof String_ || !$options instanceof Arg || !$options->value instanceof Array_ || '' === $this->scope) {
            return;
        }

        $field = $name->value->value;

        foreach ($options->value->items as $item) {
            if (!$item->key instanceof String_) {
                continue;
            }

            if (\in_array($item->key->value, self::VALUE_OPTIONS, true)) {
                $value = self::returnedBy($item->value);
                $target = ($this->dataClass ?? $this->scope).'::'.$field;
                $confidence = null === $this->dataClass ? Confidence::Medium : Confidence::High;
                $dependencies = $this->dependencies($item->value);
                $branching = $value instanceof Match_ || $value instanceof Ternary || $value instanceof Coalesce;

                $this->recordValue($target, $value, $file, [...$conditions, ...($branching || [] === $dependencies ? [] : ['depends on '.implode(', ', $dependencies)])], $confidence, $depth);
            }

            if (\in_array($item->key->value, self::FIELD_OPTIONS, true) && !$item->value instanceof ConstFetch) {
                $condition = $this->print($item->value);
                $target = $this->scope.'::'.$field.'.'.$item->key->value;
                $this->add($target, 'true', [...$conditions, $condition], $file, $item->getStartLine(), Confidence::High);
                $this->add($target, 'false', [...$conditions, self::negate($condition)], $file, $item->getStartLine(), Confidence::High);
            }
        }
    }

    /**
     * A closure stands for what it returns: `fn (SiteRepository $sites) => $sites->openIn($country)`.
     */
    private static function returnedBy(Expr $value): Expr
    {
        if ($value instanceof ArrowFunction) {
            return $value->expr;
        }

        if ($value instanceof Closure && 1 === \count($value->stmts) && $value->stmts[0] instanceof Return_ && $value->stmts[0]->expr instanceof Expr) {
            return $value->stmts[0]->expr;
        }

        return $value;
    }

    /**
     * What an option's value is computed from: the services of the form (`$this->sites`), its options
     * (`$options['user']`), the variables it captures — not the parameters a closure is called with.
     *
     * @return list<string>
     */
    private function dependencies(Expr $value): array
    {
        $parameters = $value instanceof ArrowFunction || $value instanceof Closure
            ? array_map(static fn (Param $parameter): string => $parameter->var instanceof Variable && \is_string($parameter->var->name) ? $parameter->var->name : '', $value->params)
            : [];
        $found = [];
        $inside = [];

        foreach (new NodeFinder()->find([$value], static fn (Node $node): bool => $node instanceof ArrayDimFetch || $node instanceof PropertyFetch || $node instanceof Variable) as $node) {
            if ($node instanceof ArrayDimFetch && $node->var instanceof Variable && $node->dim instanceof Expr) {
                $found[] = $this->print($node);
                $inside[spl_object_id($node->var)] = true;
            } elseif ($node instanceof PropertyFetch && $node->var instanceof Variable && 'this' === $node->var->name) {
                $found[] = $this->print($node);
                $inside[spl_object_id($node->var)] = true;
            } elseif ($node instanceof Variable && \is_string($node->name) && 'this' !== $node->name && !isset($inside[spl_object_id($node)]) && !\in_array($node->name, $parameters, true)) {
                $found[] = '$'.$node->name;
            }
        }

        return array_values(array_unique($found));
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
