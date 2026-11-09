<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Tests\Inspection\Adapter\Symfony;

use Jul6Art\DevTools\Config\Config;
use Jul6Art\DevTools\Inspection\Adapter\AdapterResult;
use Jul6Art\DevTools\Inspection\Adapter\Symfony\ConsoleFailed;
use Jul6Art\DevTools\Inspection\Adapter\Symfony\ConsoleIntrospection;
use Jul6Art\DevTools\Inspection\Adapter\Symfony\StaticSymfonyScanner;
use Jul6Art\DevTools\Inspection\Adapter\Symfony\SymfonyAdapter;
use Jul6Art\DevTools\Inspection\Graph\PhpReferenceExtractor;
use Jul6Art\DevTools\Inspection\Graph\WorkflowBuilder;
use Jul6Art\DevTools\Inspection\Model\FileRef;
use Jul6Art\DevTools\Inspection\Model\InspectionResult;
use Jul6Art\DevTools\Inspection\Model\Mechanism;
use Jul6Art\DevTools\Inspection\Model\Workflow;
use Jul6Art\DevTools\Inspection\Model\WorkflowIdDeriver;
use Jul6Art\DevTools\Tests\Inspection\Graph\GraphFixture;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * ADR-0051: an API Platform operation is a route — found in the console's routes by its resource class, its
 * provider, processor and security read in the resource's attributes.
 */
#[CoversClass(SymfonyAdapter::class)]
#[CoversClass(ConsoleIntrospection::class)]
#[CoversClass(StaticSymfonyScanner::class)]
#[CoversClass(WorkflowIdDeriver::class)]
final class ApiPlatformOperationsTest extends TestCase
{
    private const string PROJECT = __DIR__.'/../../../Fixtures/projects/symfony-api-platform';

    public function testEveryOperationOfAResourceOfTheProjectIsARouteAndNothingElseIs(): void
    {
        [$result] = $this->inspect(new RecordedConsoleRunner(project: 'symfony-api-platform'));

        self::assertSame([
            'route.api.items.get.collection',
            'route.api.items.id.delete',
            'route.api.loan.return',
            'route.api.loans.get.collection',
            'route.api.loans.id.get',
            'route.api.loans.post',
            'route.loan.return',
        ], $this->ids($result), 'Neither the vendor\'s resource (errors), nor an operation that is not exposed, nor the entrypoint are routes of the project.');
    }

    public function testAnOperationIsDeclaredByItsResourceAndSaysWhatRunsIt(): void
    {
        [$result] = $this->inspect(new RecordedConsoleRunner(project: 'symfony-api-platform'));

        $return = $this->workflow($result, 'route.api.loan.return');
        self::assertSame('api-operation', $return->main->kind);
        self::assertSame('loan_return', $return->main->name, 'The route keeps its own name: it is a fact.');
        self::assertSame('src/Entity/Loan.php', $return->main->declaredIn->path);
        self::assertSame(['methods' => 'POST', 'path' => '/api/loans/{id}/return', 'processor' => 'App\\State\\GiveBackProcessor', 'security' => 'LOAN_GIVE_BACK'], $return->main->attributes);
        self::assertSame('POST /api/loans/{id}/return', $return->title);

        $files = array_map(static fn (FileRef $file): string => $file->path, $return->files);
        self::assertContains('src/State/GiveBackProcessor.php', $files);
        self::assertContains('src/Manager/LoanManager.php', $files, 'What the processor calls is part of the operation.');
        self::assertContains('config/packages/api_platform.yaml', $files);
    }

    public function testAnOperationWithoutItsOwnSettingsTakesTheResources(): void
    {
        [$result] = $this->inspect(new RecordedConsoleRunner(project: 'symfony-api-platform'));

        self::assertSame(
            ['methods' => 'GET', 'path' => '/api/items.{_format}', 'processor' => 'App\\State\\ItemProcessor', 'provider' => 'App\\State\\ItemProvider', 'security' => 'ROLE_USER'],
            $this->workflow($result, 'route.api.items.get.collection')->main->attributes,
        );
        self::assertSame(
            ['methods' => 'DELETE', 'path' => '/api/items/{id}.{_format}', 'processor' => 'App\\State\\ItemProcessor', 'security' => 'ITEM_DELETE'],
            $this->workflow($result, 'route.api.items.id.delete')->main->attributes,
            'Its own security replaces the resource\'s; the resource\'s processor stays.',
        );
        self::assertSame(
            ['methods' => 'POST', 'path' => '/api/loans.{_format}', 'processor' => 'App\\State\\LendProcessor', 'security' => 'LOAN_CREATE'],
            $this->workflow($result, 'route.api.loans.post')->main->attributes,
            'Found by its kind and its method, without a name or a URI template.',
        );
    }

    public function testTheVoterOfAnOperationIsOneOfItsMechanisms(): void
    {
        [$result] = $this->inspect(new RecordedConsoleRunner(project: 'symfony-api-platform'));

        $voters = static fn (Workflow $workflow): array => array_values(array_map(
            static fn (Mechanism $mechanism): string => $mechanism->name,
            array_filter($workflow->mechanisms, static fn (Mechanism $mechanism): bool => 'voter' === $mechanism->kind),
        ));

        self::assertSame(['App\\Security\\LoanVoter'], $voters($this->workflow($result, 'route.api.loan.return')));
        self::assertSame([], $voters($this->workflow($result, 'route.api.items.id.delete')));
    }

    public function testWithoutTheConsoleTheOperationsAreNotGuessedAndTheReportSaysSo(): void
    {
        [$result, $adapter] = $this->inspect(new RecordedConsoleRunner(failure: new ConsoleFailed('bin/console exited with code 255'), project: 'symfony-api-platform'));

        self::assertSame(['route.loan.return'], $this->ids($result));
        self::assertContains('2 API Platform resources (App\\Entity\\Item, App\\Entity\\Loan): their operations are read from the console only, which did not answer.', $adapter->warnings);
    }

    /**
     * @return array{InspectionResult, AdapterResult}
     */
    private function inspect(RecordedConsoleRunner $runner): array
    {
        $root = GraphFixture::root(self::PROJECT);
        $stack = GraphFixture::stack(self::PROJECT);
        $extractor = new PhpReferenceExtractor();
        $adapter = new SymfonyAdapter($runner)->extract($root, $stack, new Config(), $extractor);

        return [new WorkflowBuilder(new Config())->build($root, $stack, $adapter->candidates, $adapter->templateDirectories, $extractor, $adapter->mechanisms)->result, $adapter];
    }

    /**
     * @return list<string>
     */
    private function ids(InspectionResult $result): array
    {
        $ids = array_map(static fn (Workflow $workflow): string => (string) $workflow->id, $result->workflows);
        sort($ids, \SORT_STRING);

        return $ids;
    }

    private function workflow(InspectionResult $result, string $id): Workflow
    {
        foreach ($result->workflows as $workflow) {
            if ($id === (string) $workflow->id) {
                return $workflow;
            }
        }

        self::fail(\sprintf('No workflow %s among %s.', $id, implode(', ', $this->ids($result))));
    }
}
