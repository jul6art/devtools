<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Tests\Inspection\Graph;

use Jul6Art\DevTools\Inspection\Graph\DecisionExtractor;
use Jul6Art\DevTools\Inspection\Graph\PhpReferenceExtractor;
use Jul6Art\DevTools\Inspection\Model\Confidence;
use Jul6Art\DevTools\Inspection\Model\DecisionPoint;
use Jul6Art\DevTools\Inspection\Model\FileRef;
use Jul6Art\DevTools\Tests\Support\UsesTemporaryDirectory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * ADR-0043: where the code decides that a field takes one value rather than another.
 */
#[CoversClass(DecisionExtractor::class)]
#[CoversClass(DecisionPoint::class)]
final class DecisionExtractorTest extends TestCase
{
    use UsesTemporaryDirectory;

    /**
     * @param list<array{string, string, string|null}> $expected target, value, condition
     */
    #[DataProvider('shapes')]
    public function testEachShapeOfDecisionIsRead(string $body, array $expected): void
    {
        self::assertSame($expected, self::triples($this->extract($body)));
    }

    /**
     * @return iterable<string, array{string, list<array{string, string|null, string|null}>}>
     */
    public static function shapes(): iterable
    {
        yield 'if / else' => [
            "if (\$order->paid) {\n    \$order->setStatus('paid');\n} else {\n    \$order->setStatus('due');\n}",
            [
                ['App\Entity\Order::status', "'paid'", '$order->paid'],
                ['App\Entity\Order::status', "'due'", '!($order->paid)'],
            ],
        ];

        yield 'elseif' => [
            "if (\$order->paid) {\n    \$order->setStatus('paid');\n} elseif (\$order->sent) {\n    \$order->setStatus('sent');\n} else {\n    \$order->setStatus('due');\n}",
            [
                ['App\Entity\Order::status', "'paid'", '$order->paid'],
                ['App\Entity\Order::status', "'sent'", '$order->sent'],
                ['App\Entity\Order::status', "'due'", '!($order->paid || $order->sent)'],
            ],
        ];

        yield 'match as a decided value' => [
            "\$order->setStatus(match (\$order->country) {\n    'CH' => 'chf',\n    default => 'eur',\n});",
            [
                ['App\Entity\Order::status', "'chf'", "\$order->country === 'CH'"],
                ['App\Entity\Order::status', "'eur'", '$order->country — default'],
            ],
        ];

        yield 'ternary as a decided value' => [
            "\$order->setStatus(\$order->paid ? 'paid' : 'due');",
            [
                ['App\Entity\Order::status', "'paid'", '$order->paid'],
                ['App\Entity\Order::status', "'due'", '!($order->paid)'],
            ],
        ];

        yield 'coalesce as a decided value' => [
            "\$order->setStatus(\$order->wanted ?? 'due');",
            [
                ['App\Entity\Order::status', '$order->wanted', '!(null === $order->wanted)'],
                ['App\Entity\Order::status', "'due'", 'null === $order->wanted'],
            ],
        ];

        yield 'property written directly' => [
            "if (\$order->paid) {\n    \$order->status = 'paid';\n} else {\n    \$order->status = 'due';\n}",
            [
                ['App\Entity\Order::status', "'paid'", '$order->paid'],
                ['App\Entity\Order::status', "'due'", '!($order->paid)'],
            ],
        ];
    }

    /**
     * An assignment written once, with no condition, answers no question: it is a default, and drawing a
     * diagram for it is the inventory ADR-0043 removed.
     */
    public function testAnUnconditionalSingleAssignmentIsNotADecision(): void
    {
        self::assertSame([], self::triples(DecisionPoint::branching($this->extract("\$order->setStatus('draft');"))));
    }

    public function testTwoUnconditionalValuesForTheSameFieldAreADecision(): void
    {
        $points = DecisionPoint::branching($this->extract("\$order->setStatus('draft');\n\$order->setStatus('sent');"));

        self::assertSame(["'draft'", "'sent'"], array_map(static fn (DecisionPoint $point): string => $point->value, $points));
    }

    /**
     * A return of a class constant or an enumeration case is the decided value of the method itself.
     */
    public function testAMethodReturningAConstantUnderAConditionIsADecision(): void
    {
        $points = $this->extract("if (\$order->paid) {\n    return \\App\\Enum\\Status::PAID;\n}\n\nreturn \\App\\Enum\\Status::DUE;");

        self::assertSame([
            ['App\Service\Pricing::price', '\App\Enum\Status::PAID', '$order->paid'],
            ['App\Service\Pricing::price', '\App\Enum\Status::DUE', null],
        ], self::triples($points));
    }

    /**
     * The receiver's type is read from the parameter; without it the name as written is kept, at a lower
     * confidence — named, never invented, never silently dropped.
     */
    public function testAReceiverWithoutAKnownTypeIsRecordedAtALowerConfidence(): void
    {
        $points = $this->extract("if (\$order->paid) {\n    \$whatever->setStatus('paid');\n} else {\n    \$whatever->setStatus('due');\n}");

        self::assertSame('whatever::status', $points[0]->target);
        self::assertSame(Confidence::Medium, $points[0]->confidence);
        self::assertSame(Confidence::High, $this->extract("if (\$order->paid) {\n    \$order->setStatus('p');\n} else {\n    \$order->setStatus('d');\n}")[0]->confidence);
    }

    /**
     * A fluent chain writes on the object it started from: the target is that object's type, not the
     * printed chain. Before this, `(new RolePermission())->setRoleCode($code)->setPermission($p)`
     * produced a target carrying spaces — which no page draft could ever quote back, since a target is
     * named as one word.
     */
    public function testAFluentChainIsNamedAfterTheObjectItStartedFrom(): void
    {
        $points = $this->extract(
            "if (\$order->paid) {\n    (new \\App\\Entity\\Line())->setLabel('a')->setAmount(1);\n} else {\n    (new \\App\\Entity\\Line())->setLabel('b')->setAmount(2);\n}",
        );

        self::assertSame('App\Entity\Line::amount', $points[0]->target);
        self::assertSame(Confidence::High, $points[0]->confidence);
    }

    /**
     * Only fluent names are walked up: a lookup returns something else entirely, and following it would
     * name the repository's type with the confidence of a certainty.
     */
    public function testALookupIsNotWalkedUpAndNeverCarriesASpace(): void
    {
        $points = $this->extract(
            "if (\$order->paid) {\n    \$repository->find(1, true)->setStatus('a');\n} else {\n    \$repository->find(1, true)->setStatus('b');\n}",
        );

        self::assertStringNotContainsString(' ', $points[0]->target);
        self::assertSame(Confidence::Medium, $points[0]->confidence);
    }

    public function testBeyondThreeNestedConditionsTheReadingStopsAndSaysSo(): void
    {
        $body = "if (\$a) {\n if (\$b) {\n  if (\$c) {\n   if (\$d) {\n    \$order->setStatus('deep');\n   }\n  }\n }\n}";
        [$extractor, $points] = $this->extractWith($body);

        self::assertSame([], self::triples($points));
        self::assertTrue($extractor->truncated(), 'The bound is reported, not hidden.');
    }

    public function testAtThreeNestedConditionsTheDecisionIsStillRead(): void
    {
        [$extractor, $points] = $this->extractWith("if (\$a) {\n if (\$b) {\n  if (\$c) {\n   \$order->setStatus('deep');\n  }\n }\n}");

        self::assertSame([['App\Entity\Order::status', "'deep'", '$a && $b && $c']], self::triples($points));
        self::assertFalse($extractor->truncated());
    }

    public function testPastFiftyDecisionsTheReadingStopsAndSaysSo(): void
    {
        $body = '';

        for ($i = 0; $i < 60; ++$i) {
            $body .= \sprintf("if (\$c%d) {\n    \$order->setStatus('s%d');\n}\n", $i, $i);
        }

        [$extractor, $points] = $this->extractWith($body);

        self::assertCount(DecisionExtractor::MAX_POINTS, $points);
        self::assertTrue($extractor->truncated());
    }

    public function testAFileThatCannotBeParsedYieldsNothing(): void
    {
        $file = $this->temporaryDirectory().'/broken.php';
        file_put_contents($file, "<?php\n\nclass {");

        self::assertSame([], new DecisionExtractor(new PhpReferenceExtractor())->extract($file, new FileRef('broken.php')));
    }

    /**
     * Only the named method is read, as the dependency graph does: the list route must not inherit the
     * decisions of the creation route.
     */
    public function testOnlyTheNamedMethodsAreRead(): void
    {
        $file = $this->temporaryDirectory().'/Scoped.php';
        file_put_contents($file, <<<'PHP'
            <?php

            namespace App\Controller;

            use App\Entity\Order;

            final class Scoped
            {
                public function create(Order $order): void
                {
                    $order->setStatus($order->paid ? 'paid' : 'due');
                }

                public function list(Order $order): void
                {
                    $order->setLabel($order->paid ? 'a' : 'b');
                }
            }
            PHP);

        $extractor = new DecisionExtractor(new PhpReferenceExtractor());

        self::assertSame(['App\Entity\Order::status'], self::targets($extractor->extract($file, new FileRef('src/Controller/Scoped.php'), ['create'])));
        self::assertSame(['App\Entity\Order::label'], self::targets($extractor->extract($file, new FileRef('src/Controller/Scoped.php'), ['list'])));
    }

    /**
     * @return list<DecisionPoint>
     */
    private function extract(string $body): array
    {
        return $this->extractWith($body)[1];
    }

    /**
     * @return array{DecisionExtractor, list<DecisionPoint>}
     */
    private function extractWith(string $body): array
    {
        $file = $this->temporaryDirectory().'/Pricing'.substr(md5($body), 0, 8).'.php';
        file_put_contents($file, \sprintf(<<<'PHP'
            <?php

            namespace App\Service;

            use App\Entity\Order;

            final class Pricing
            {
                public function price(Order $order, mixed $whatever = null, mixed $a = null, mixed $b = null, mixed $c = null, mixed $d = null): mixed
                {
                    %s

                    return null;
                }
            }
            PHP, str_replace("\n", "\n        ", $body)));

        $extractor = new DecisionExtractor(new PhpReferenceExtractor());

        return [$extractor, $extractor->extract($file, new FileRef('src/Service/Pricing.php'))];
    }

    /**
     * @param list<DecisionPoint> $points
     *
     * @return list<string>
     */
    private static function targets(array $points): array
    {
        return array_values(array_unique(array_map(static fn (DecisionPoint $point): string => $point->target, $points)));
    }

    /**
     * @param list<DecisionPoint> $points
     *
     * @return list<array{string, string, string|null}>
     */
    private static function triples(array $points): array
    {
        return array_map(static fn (DecisionPoint $point): array => [$point->target, $point->value, $point->condition], $points);
    }
}
