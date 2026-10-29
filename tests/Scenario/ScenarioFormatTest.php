<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Tests\Scenario;

use Jul6Art\DevTools\Inspection\Model\WorkflowId;
use Jul6Art\DevTools\Scenario\Scenario;
use Jul6Art\DevTools\Scenario\ScenarioIndex;
use Jul6Art\DevTools\Scenario\ScenarioIndexEntry;
use Jul6Art\DevTools\Scenario\ScenarioOrigin;
use Jul6Art\DevTools\Scenario\ScenarioSkeleton;
use Jul6Art\DevTools\Scenario\Step;
use Jul6Art\DevTools\Scenario\XmlScenarioIndexStore;
use Jul6Art\DevTools\Scenario\XmlScenarioStore;
use Jul6Art\DevTools\Tests\Support\UsesTemporaryDirectory;
use Jul6Art\DevTools\Xml\InvalidXml;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

/**
 * ADR-0020: the format of a scenario — the closed vocabulary of `scenario.xsd`, what it refuses, and the same
 * bytes for the same scenario.
 */
#[CoversClass(Scenario::class)]
#[CoversClass(Step::class)]
#[CoversClass(XmlScenarioStore::class)]
#[CoversClass(ScenarioIndex::class)]
#[CoversClass(ScenarioIndexEntry::class)]
#[CoversClass(XmlScenarioIndexStore::class)]
#[CoversClass(ScenarioSkeleton::class)]
final class ScenarioFormatTest extends TestCase
{
    use UsesTemporaryDirectory;

    private const string EXAMPLE = __DIR__.'/../Fixtures/scenarios/happy-path.xml';

    public function testTheExampleOfTheDecisionIsValid(): void
    {
        $scenario = new XmlScenarioStore()->read(self::EXAMPLE);

        self::assertSame('route.order.new/happy-path', $scenario->id);
        self::assertSame('happy-path', $scenario->name);
        self::assertSame('ROLE_OPERATOR', $scenario->login);
        self::assertSame(['catalog-loaded'], $scenario->fixtures);
        self::assertSame(['goto', 'fill', 'select2', 'click', 'assert-route', 'assert-visible', 'screenshot'], array_map(static fn (Step $step): string => $step->action, $scenario->steps));
        self::assertSame(['field' => 'order[product]', 'search' => 'Widget', 'pick' => 'Widget XL'], $scenario->steps[2]->arguments);
        self::assertSame(['app_order_new', 'app_order_show'], $scenario->routes());
    }

    /**
     * Written by DevTools, a scenario reads back to itself, and writes to the same bytes.
     */
    public function testAScenarioWrittenReadsBackToTheSameBytes(): void
    {
        $store = new XmlScenarioStore();
        $xml = $store->serialize($store->read(self::EXAMPLE));
        $scenario = new Scenario(new WorkflowId('route.order.show'), 'by-id', [new Step('goto', ['route' => 'app_order_show'], ['id' => '1']), new Step('assert-count', ['count' => '2', 'selector' => 'tr'])]);

        self::assertSame($xml, $store->serialize($store->unserialize($xml, 'memory')));
        self::assertStringContainsString('<assert-count selector="tr" count="2"/>', $store->serialize($scenario), 'The attributes in the order of the vocabulary, whatever the order given.');
        self::assertStringContainsString('<param name="id" value="1"/>', $store->serialize($scenario));
        self::assertEquals($scenario, $store->unserialize($store->serialize($scenario), 'memory'));
        self::assertStringNotContainsString('<given', $store->serialize($scenario), 'Anonymous and without data: no given at all.');
    }

    /**
     * @param non-empty-string $cause
     */
    #[DataProvider('refused')]
    public function testWhatTheVocabularyDoesNotSayIsRefused(string $from, string $to, string $cause): void
    {
        $example = (string) file_get_contents(self::EXAMPLE);
        $xml = str_starts_with($from, '/') ? (string) preg_replace($from, $to, $example) : str_replace($from, $to, $example);
        self::assertNotSame($example, $xml, 'The mutation applies.');

        $this->expectException(InvalidXml::class);
        $this->expectExceptionMessageMatches('/'.$cause.'/');

        new XmlScenarioStore()->unserialize($xml, 'happy-path.xml');
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function refused(): iterable
    {
        yield 'an unknown action' => ['<click text="Enregistrer"/>', '<double-click text="Enregistrer"/>', 'double-click'];
        yield 'an attribute the action does not take' => ['<assert-route name="app_order_show"/>', '<assert-route name="app_order_show" timeout="3"/>', 'timeout'];
        yield 'no workflow' => [' workflow="route.order.new"', '', 'workflow'];
        yield 'a malformed identifier' => ['id="route.order.new/happy-path"', 'id="route.order.new/Happy Path"', 'id'];
        yield 'an identifier of another workflow' => ['id="route.order.new/happy-path"', 'id="route.order.show/happy-path"', 'belongs to the workflow its identifier starts with'];
        yield 'a click on a text and a selector' => ['<click text="Enregistrer"/>', '<click text="Enregistrer" selector="button"/>', 'a text or a selector'];
        yield 'a click on nothing' => ['<click text="Enregistrer"/>', '<click/>', 'a text or a selector'];
        yield 'no step' => ['/<steps>.*<\/steps>/s', '<steps/>', 'steps'];
        yield 'a draft read as a scenario' => ['<scenario xmlns', '<scenarios xmlns', 'scenarios'];
        yield 'a parameter of something else than a goto' => ['<assert-route name="app_order_show"/>', '<assert-route name="app_order_show"><param name="id" value="1"/></assert-route>', 'content is not allowed'];
    }

    public function testADraftHoldsSeveralScenarios(): void
    {
        $draft = $this->temporaryDirectory().'/draft.xml';
        $example = (string) file_get_contents(self::EXAMPLE);
        self::assertSame(1, preg_match('/<scenario xmlns.*<\/scenario>/s', $example, $scenario));
        $inner = str_replace([' xmlns="https://github.com/jul6art/devtools/schema/scenario/1" schema-version="1"', '<!-- résolu par un LoginProvider du projet -->'], '', $scenario[0]);
        file_put_contents($draft, '<scenarios xmlns="https://github.com/jul6art/devtools/schema/scenario/1" schema-version="1">'.$inner.str_replace('happy-path"', 'second"', $inner).'</scenarios>');

        self::assertSame(['route.order.new/happy-path', 'route.order.new/second'], array_map(static fn (Scenario $scenario): string => $scenario->id, new XmlScenarioStore()->readDraft($draft)));

        $this->expectException(InvalidXml::class);
        $this->expectExceptionMessageIsOrContains('expected <scenarios>, found <scenario>');
        new XmlScenarioStore()->readDraft(self::EXAMPLE);
    }

    #[TestWith(['', 'is not a scenario name'])]
    #[TestWith(['Upper', 'is not a scenario name'])]
    public function testANameIsAFileName(string $name, string $cause): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains($cause);

        new Scenario(new WorkflowId('route.health'), $name, [new Step('goto', ['route' => 'app_health'])]);
    }

    public function testAScenarioHasAtLeastOneStep(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('has no step');

        new Scenario(new WorkflowId('route.health'), 'empty', []);
    }

    /**
     * @param array<string, string> $arguments
     * @param array<string, string> $parameters
     */
    #[TestWith(['wave', ['at' => 'me'], [], 'is not an action'])]
    #[TestWith(['goto', ['to' => 'app_health'], [], 'takes no "to"'])]
    #[TestWith(['fill', ['field' => 'a', 'value' => 'b'], ['id' => '1'], 'Only a goto'])]
    #[TestWith(['click', [], [], 'a text or a selector'])]
    public function testAStepOutsideTheVocabularyIsRefused(string $action, array $arguments, array $parameters, string $cause): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains($cause);

        new Step($action, $arguments, $parameters);
    }

    public function testTheIndexKeepsOneEntryPerScenarioSortedAndRoundTrips(): void
    {
        $file = $this->temporaryDirectory().'/scenarios/index.xml';
        $at = new \DateTimeImmutable('2026-09-16T15:00:00+02:00');
        $index = new ScenarioIndex([new ScenarioIndexEntry('route.order.new/smoke', ScenarioOrigin::Skeleton, str_repeat('a', 64), $at), new ScenarioIndexEntry('route.health/smoke', ScenarioOrigin::Human, str_repeat('b', 64), $at)]);
        $index = $index->with(new ScenarioIndexEntry('route.health/smoke', ScenarioOrigin::Claude, str_repeat('c', 64), $at));
        $store = new XmlScenarioIndexStore();

        self::assertSame(['route.health/smoke', 'route.order.new/smoke'], array_map(static fn (ScenarioIndexEntry $entry): string => $entry->id, $index->entries));
        self::assertSame(ScenarioOrigin::Claude, $index->entry('route.health/smoke')?->origin);
        self::assertCount(1, $index->of(new WorkflowId('route.order.new')));
        self::assertNull($index->without('route.health/smoke')->entry('route.health/smoke'));
        self::assertEquals(new ScenarioIndex(), $store->read($file), 'No index: no scenario.');

        $store->write($file, $index);

        self::assertEquals($index, $store->read($file));
        self::assertFalse($store->write($file, $store->read($file)), 'Read and written again: the same bytes.');
    }

    public function testAnIndexEntryIsAScenarioIdentifier(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ScenarioIndexEntry('route.health', ScenarioOrigin::Human, str_repeat('a', 64), new \DateTimeImmutable());
    }

    #[TestWith(['ROLE_USER, ROLE_OPERATOR', 'ROLE_OPERATOR'])]
    #[TestWith(["is_granted('ROLE_ADMIN') and IS_AUTHENTICATED_FULLY", 'ROLE_ADMIN'])]
    #[TestWith(['IS_AUTHENTICATED_FULLY', 'IS_AUTHENTICATED_FULLY'])]
    #[TestWith(['PUBLIC_ACCESS', null])]
    #[TestWith([null, null])]
    public function testTheRoleIsTheMostSpecificTheSecurityNames(?string $security, ?string $role): void
    {
        self::assertSame($role, ScenarioSkeleton::role($security));
    }
}
