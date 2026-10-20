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
     * ADR-0044 § 4: `$order->setStatus($this->decide($order))` decides in `decide()`. The conditions of the
     * called method come up into the point, prefixed with its name — a branch that returns makes the
     * statements after it conditional on the opposite.
     */
    public function testACallToAMethodOfTheSameClassBringsUpItsConditionsPrefixed(): void
    {
        $points = $this->extractClass(<<<'PHP'
            public function price(Order $order): void
            {
                if ($order->locked) {
                    $order->setStatus($this->decide($order));
                }
            }

            private function decide(Order $order): string
            {
                if ($order->paid) {
                    return 'paid';
                }

                if ($order->sent) {
                    return 'sent';
                }

                return 'due';
            }
            PHP, ['price']);

        self::assertSame([
            ['App\Entity\Order::status', "'paid'", '$order->locked && decide() : $order->paid'],
            ['App\Entity\Order::status', "'sent'", '$order->locked && decide() : !($order->paid) && $order->sent'],
            ['App\Entity\Order::status', "'due'", '$order->locked && decide() : !($order->paid) && !($order->sent)'],
        ], self::triples($points));
    }

    public function testATernaryOrAMatchReturnedByTheCalledMethodIsSplitInsideIt(): void
    {
        $points = $this->extractClass(<<<'PHP'
            public function price(Order $order): void
            {
                $order->status = self::decide($order);
            }

            private static function decide(Order $order): string
            {
                return match ($order->country) {
                    'CH' => 'chf',
                    default => $order->paid ? 'eur' : 'due',
                };
            }
            PHP);

        self::assertSame([
            ['App\Entity\Order::status', "'chf'", "decide() : \$order->country === 'CH'"],
            ['App\Entity\Order::status', "'eur'", 'decide() : $order->country — default && $order->paid'],
            ['App\Entity\Order::status', "'due'", 'decide() : $order->country — default && !($order->paid)'],
        ], self::triples($points));
    }

    /**
     * One hop, never two: at the third place in the code, the condition no longer reads.
     */
    public function testASecondHopIsNotFollowed(): void
    {
        $points = $this->extractClass(<<<'PHP'
            public function price(Order $order): void
            {
                $order->setStatus($this->decide($order));
            }

            private function decide(Order $order): string
            {
                if ($order->paid) {
                    return 'paid';
                }

                return $this->deeper($order);
            }

            private function deeper(Order $order): string
            {
                return $order->sent ? 'sent' : 'due';
            }
            PHP, ['price']);

        self::assertSame([
            ['App\Entity\Order::status', "'paid'", 'decide() : $order->paid'],
            ['App\Entity\Order::status', '$this->deeper($order)', 'decide() : !($order->paid)'],
        ], self::triples($points));
    }

    /**
     * An `if` / `else` that returns on both sides ends the method: nothing after it is reachable, and a
     * branch that does not return leaves what follows unconditional on it.
     */
    public function testAnIfElseReturningOnBothSidesEndsTheCalledMethod(): void
    {
        $points = $this->extractClass(<<<'PHP'
            public function price(Order $order): void
            {
                $order->setStatus($this->decide($order));
            }

            private function decide(Order $order): string
            {
                if ($order->archived) {
                    $order->touch();
                }

                if ($order->paid) {
                    return 'paid';
                } elseif ($order->sent) {
                    return 'sent';
                } else {
                    return 'due';
                }

                return 'unreachable';
            }
            PHP, ['price']);

        self::assertSame([
            ['App\Entity\Order::status', "'paid'", 'decide() : $order->paid'],
            ['App\Entity\Order::status', "'sent'", 'decide() : $order->sent'],
            ['App\Entity\Order::status', "'due'", 'decide() : !($order->paid || $order->sent)'],
        ], self::triples($points));
    }

    public function testACalledMethodThatReturnsNothingLeavesTheCallAsWritten(): void
    {
        $points = $this->extractClass(<<<'PHP'
            public function price(Order $order): void
            {
                $order->setStatus($order->paid ? $this->decide($order) : 'due');
            }

            private function decide(Order $order): string
            {
                foreach ($order->lines as $line) {
                    return $line->status;
                }

                throw new \LogicException('No line.');
            }
            PHP);

        self::assertSame([
            ['App\Entity\Order::status', '$this->decide($order)', '$order->paid'],
            ['App\Entity\Order::status', "'due'", '!($order->paid)'],
        ], self::triples($points), 'A return inside a loop is not read: the call stays the value.');
    }

    /**
     * A choice list given by a callback, and the constraints of a `When` that apply otherwise.
     */
    public function testAChoiceCallbackAndTheOtherwiseOfAWhenAreReadToo(): void
    {
        $file = $this->temporaryDirectory().'/Invoice.php';
        file_put_contents($file, <<<'PHP'
            <?php

            namespace App\Entity;

            use Symfony\Component\Validator\Constraints as Assert;

            final class Invoice
            {
                #[Assert\Choice(callback: [self::class, 'currencies'])]
                public string $currency = 'EUR';

                #[Assert\When(expression: 'this.paid', constraints: [new Assert\NotNull()], otherwise: [new Assert\IsNull()])]
                public ?\DateTimeImmutable $paidAt = null;
            }
            PHP);

        self::assertSame([
            ['App\Entity\Invoice::currency', "callback: [self::class, 'currencies']", null],
            ['App\Entity\Invoice::paidAt', 'NotNull', 'this.paid'],
            ['App\Entity\Invoice::paidAt', 'IsNull', '!(this.paid)'],
        ], self::triples(new DecisionExtractor(new PhpReferenceExtractor())->extract($file, new FileRef('src/Entity/Invoice.php'))));
    }

    /**
     * A call on another object is not followed: its body is not the class being read, and guessing which
     * class it is would name a decision on faith.
     */
    public function testACallOnAnotherObjectIsRecordedAsWritten(): void
    {
        $points = $this->extractClass(<<<'PHP'
            public function price(Order $order, Rules $rules): void
            {
                $order->setStatus($order->paid ? $rules->decide($order) : 'due');
            }
            PHP);

        self::assertSame([
            ['App\Entity\Order::status', '$rules->decide($order)', '$order->paid'],
            ['App\Entity\Order::status', "'due'", '!($order->paid)'],
        ], self::triples($points));
    }

    /**
     * ADR-0044 § 3: a validation constraint bounds a field before it reaches the database — one case per
     * constraint DevTools reads.
     *
     * @param list<array{string, string, string|null}> $expected
     */
    #[DataProvider('constraints')]
    public function testEachValidationConstraintIsADecisionOnTheAnnotatedProperty(string $property, array $expected): void
    {
        $file = $this->temporaryDirectory().'/Customer.php';
        file_put_contents($file, \sprintf(<<<'PHP'
            <?php

            namespace App\Entity;

            use Symfony\Component\Validator\Constraints as Assert;

            final class Customer
            {
                %s

                #[Assert\NotBlank]
                public string $name = '';
            }
            PHP, $property));

        self::assertSame($expected, self::triples(new DecisionExtractor(new PhpReferenceExtractor())->extract($file, new FileRef('src/Entity/Customer.php'))));
    }

    /**
     * @return iterable<string, array{string, list<array{string, string, string|null}>}>
     */
    public static function constraints(): iterable
    {
        yield 'Choice: one value per admitted choice' => [
            "#[Assert\\Choice(['standard', 'gold'])]\n    public string \$tier = 'standard';",
            [['App\Entity\Customer::tier', "'standard'", null], ['App\Entity\Customer::tier', "'gold'", null]],
        ];

        yield 'Choice in groups: the groups are the condition' => [
            "#[Assert\\Choice(choices: ['a', 'b'], groups: ['strict', 'import'])]\n    public string \$grade = 'a';",
            [['App\Entity\Customer::grade', "'a'", 'groups: strict, import'], ['App\Entity\Customer::grade', "'b'", 'groups: strict, import']],
        ];

        yield 'Expression: valid under it, invalid under its opposite' => [
            "#[Assert\\Expression('this.limit >= 0 or this.tier == \"gold\"')]\n    public int \$limit = 0;",
            [['App\Entity\Customer::limit', 'valid', 'this.limit >= 0 or this.tier == "gold"'], ['App\Entity\Customer::limit', 'invalid', '!(this.limit >= 0 or this.tier == "gold")']],
        ];

        yield 'When: its constraints under its expression' => [
            "#[Assert\\When(expression: 'this.country == \"CH\"', constraints: [new Assert\\NotBlank(), new Assert\\Length(max: 12)])]\n    public string \$vat = '';",
            [['App\Entity\Customer::vat', 'NotBlank, Length(max: 12)', 'this.country == "CH"']],
        ];
    }

    /**
     * ADR-0044 § 3: the values a form field may take are decided on the entity's property, not on the
     * form — and a field enabled or required under a condition is a decision on the form itself.
     */
    public function testAFormFieldDecidesTheChoicesOfItsPropertyAndWhetherItIsOpen(): void
    {
        $file = $this->temporaryDirectory().'/CustomerType.php';
        file_put_contents($file, <<<'PHP'
            <?php

            namespace App\Form;

            use App\Entity\Customer;
            use App\Entity\Region;
            use App\Entity\Site;
            use App\Repository\RegionRepository;
            use App\Repository\SiteRepository;
            use Symfony\Bridge\Doctrine\Form\Type\EntityType;
            use Symfony\Component\Form\AbstractType;
            use Symfony\Component\Form\Extension\Core\Type\TextType;
            use Symfony\Component\Form\FormBuilderInterface;
            use Symfony\Component\OptionsResolver\OptionsResolver;

            final class CustomerType extends AbstractType
            {
                public function __construct(private readonly SiteRepository $sites)
                {
                }

                public function buildForm(FormBuilderInterface $builder, array $options): void
                {
                    $builder->add('name', TextType::class, ['required' => true]);
                    $builder->add('site', EntityType::class, [
                        'class' => Site::class,
                        'choices' => $this->sites->visibleTo($options['user']),
                        'disabled' => !$options['can_change_site'],
                    ]);
                    $builder->add('region', EntityType::class, [
                        'class' => Region::class,
                        'query_builder' => static fn (RegionRepository $regions) => $regions->openIn($options['country']),
                    ]);
                }

                public function configureOptions(OptionsResolver $resolver): void
                {
                    $resolver->setDefaults(['data_class' => Customer::class]);
                }
            }
            PHP);

        self::assertSame([
            ['App\Entity\Customer::site', '$this->sites->visibleTo($options[\'user\'])', 'depends on $this->sites, $options[\'user\']'],
            ['App\Form\CustomerType::site.disabled', 'true', '!$options[\'can_change_site\']'],
            ['App\Form\CustomerType::site.disabled', 'false', '!(!$options[\'can_change_site\'])'],
            ['App\Entity\Customer::region', '$regions->openIn($options[\'country\'])', 'depends on $options[\'country\']'],
        ], self::triples(DecisionPoint::branching(new DecisionExtractor(new PhpReferenceExtractor())->extract($file, new FileRef('src/Form/CustomerType.php')))), 'As the builder keeps them: `setDefaults()` is a setter written once, not a decision.');
    }

    public function testWithoutADataClassTheChoicesAreDecidedOnTheFormAtALowerConfidence(): void
    {
        $file = $this->temporaryDirectory().'/LooseType.php';
        file_put_contents($file, <<<'PHP'
            <?php

            namespace App\Form;

            use Symfony\Component\Form\AbstractType;
            use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
            use Symfony\Component\Form\FormBuilderInterface;

            final class LooseType extends AbstractType
            {
                public function buildForm(FormBuilderInterface $builder, array $options): void
                {
                    $builder->add('size', ChoiceType::class, ['choices' => $options['large'] ? ['XL' => 'xl'] : ['S' => 's']]);
                }
            }
            PHP);

        $points = new DecisionExtractor(new PhpReferenceExtractor())->extract($file, new FileRef('src/Form/LooseType.php'));

        self::assertSame([
            ['App\Form\LooseType::size', "['XL' => 'xl']", "\$options['large']"],
            ['App\Form\LooseType::size', "['S' => 's']", "!(\$options['large'])"],
        ], self::triples($points));
        self::assertSame(Confidence::Medium, $points[0]->confidence);
    }

    /**
     * ADR-0044 § 2: what a voter decides is access — granted or denied, under the condition written, one
     * hop into the methods of the voter itself.
     */
    public function testAVoterDecidesAccessGrantedOrDenied(): void
    {
        $file = $this->temporaryDirectory().'/CustomerVoter.php';
        file_put_contents($file, <<<'PHP'
            <?php

            namespace App\Security;

            use App\Entity\Customer;
            use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
            use Symfony\Component\Security\Core\Authorization\Voter\Vote;
            use Symfony\Component\Security\Core\Authorization\Voter\Voter;
            use Symfony\Component\Security\Core\User\UserInterface;

            final class CustomerVoter extends Voter
            {
                public const string EDIT = 'CUSTOMER_EDIT';

                protected function supports(string $attribute, mixed $subject): bool
                {
                    return self::EDIT === $attribute && $subject instanceof Customer;
                }

                protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
                {
                    $user = $token->getUser();

                    if (!$user instanceof UserInterface) {
                        return false;
                    }

                    if (\in_array('ROLE_MANAGER', $user->getRoles(), true)) {
                        return true;
                    }

                    return $this->manages($user, $subject);
                }

                private function manages(UserInterface $user, Customer $customer): bool
                {
                    return $customer->accountManager === $user->getUserIdentifier();
                }
            }
            PHP);

        $extractor = new DecisionExtractor(new PhpReferenceExtractor());

        self::assertSame([
            ['access', 'denied', '!$user instanceof \Symfony\Component\Security\Core\User\UserInterface'],
            ['access', 'granted', "!(!\$user instanceof \\Symfony\\Component\\Security\\Core\\User\\UserInterface) && \\in_array('ROLE_MANAGER', \$user->getRoles(), true)"],
            ['access', 'granted', "!(!\$user instanceof \\Symfony\\Component\\Security\\Core\\User\\UserInterface) && !(\\in_array('ROLE_MANAGER', \$user->getRoles(), true)) && manages() : \$customer->accountManager === \$user->getUserIdentifier()"],
            ['access', 'denied', "!(!\$user instanceof \\Symfony\\Component\\Security\\Core\\User\\UserInterface) && !(\\in_array('ROLE_MANAGER', \$user->getRoles(), true)) && manages() : !(\$customer->accountManager === \$user->getUserIdentifier())"],
        ], self::triples($extractor->votes($file, new FileRef('src/Security/CustomerVoter.php'))));
        self::assertSame([], $extractor->extract($file, new FileRef('src/Security/CustomerVoter.php')), 'A vote is not a field: the general reading finds nothing there.');
    }

    public function testAClassWithoutVoteOnAttributeVotesNothing(): void
    {
        $file = $this->temporaryDirectory().'/Plain.php';
        file_put_contents($file, "<?php\n\nnamespace App;\n\nfinal class Plain\n{\n}\n");

        self::assertSame([], new DecisionExtractor(new PhpReferenceExtractor())->votes($file, new FileRef('src/Plain.php')));

        file_put_contents($file, "<?php\n\nclass {");
        self::assertSame([], new DecisionExtractor(new PhpReferenceExtractor())->votes($file, new FileRef('src/Plain.php')), 'A file that cannot be parsed votes nothing.');
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
     * A service of the project with the given methods, read whole or only for the named methods.
     *
     * @param list<string> $methods
     *
     * @return list<DecisionPoint>
     */
    private function extractClass(string $members, array $methods = []): array
    {
        $file = $this->temporaryDirectory().'/Rules'.substr(md5($members), 0, 8).'.php';
        file_put_contents($file, \sprintf(<<<'PHP'
            <?php

            namespace App\Service;

            use App\Entity\Order;

            final class Rules
            {
                %s
            }
            PHP, str_replace("\n", "\n    ", $members)));

        return new DecisionExtractor(new PhpReferenceExtractor())->extract($file, new FileRef('src/Service/Rules.php'), $methods);
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
