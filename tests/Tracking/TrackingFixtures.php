<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Tests\Tracking;

use Jul6Art\DevTools\Inspection\Model\Confidence;
use Jul6Art\DevTools\Inspection\Model\FileRef;
use Jul6Art\DevTools\Inspection\Model\FileRole;
use Jul6Art\DevTools\Inspection\Model\PackageRef;
use Jul6Art\DevTools\Inspection\Model\WorkflowId;
use Jul6Art\DevTools\Inspection\Model\WorkflowSource;
use Jul6Art\DevTools\Inspection\Model\WorkflowType;
use Jul6Art\DevTools\Tests\Inspection\Model\ModelFixtures;
use Jul6Art\DevTools\Tracking\Generation;
use Jul6Art\DevTools\Tracking\GenerationMode;
use Jul6Art\DevTools\Tracking\Revision;
use Jul6Art\DevTools\Tracking\TrackedFile;
use Jul6Art\DevTools\Tracking\TrackingDocument;
use Jul6Art\DevTools\Tracking\TrackingStatus;
use Jul6Art\DevTools\Tracking\VcsState;

final class TrackingFixtures
{
    public const string HASH_A = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    public const string HASH_B = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    public static function orderNew(TrackingStatus $status = TrackingStatus::Fresh): TrackingDocument
    {
        return new TrackingDocument(
            id: new WorkflowId('route.order.new'),
            type: WorkflowType::routes(),
            title: 'Order creation',
            generated: new Generation(new \DateTimeImmutable('2026-09-16T14:22:31+02:00'), 'devtools 0.1.0', GenerationMode::Ai, 'claude-opus-5', 'page/1'),
            vcs: new VcsState('a1b2c3d4e5f6a7b8c9d0a1b2c3d4e5f6a7b8c9d0', 'main', false),
            main: ModelFixtures::routeEntryPoint(),
            satellites: [ModelFixtures::routeEntryPoint('app_order_new_localized')],
            files: [
                new TrackedFile(new FileRef('templates/order/new.html.twig', FileRole::Template), self::HASH_B),
                new TrackedFile(new FileRef('src/Controller/OrderController.php', FileRole::Controller), self::HASH_A),
            ],
            tests: [new FileRef('tests/Controller/OrderControllerTest.php', FileRole::Test)],
            packages: [new PackageRef('symfony/form', '7.4.3')],
            dependsOn: [new WorkflowId('route.order.show')],
            confidence: Confidence::High,
            producer: new WorkflowSource('native:symfony'),
            status: $status,
            history: [
                new Revision(new \DateTimeImmutable('2026-08-02T10:01:00+02:00'), '9f8e7d6c5b4a39281706f5e4d3c2b1a098765432', 'initial'),
                new Revision(new \DateTimeImmutable('2026-09-16T14:22:31+02:00'), 'a1b2c3d4e5f6a7b8c9d0a1b2c3d4e5f6a7b8c9d0', 'files changed: src/Service/OrderPricing.php'),
            ],
        );
    }

    public static function importCatalog(): TrackingDocument
    {
        return new TrackingDocument(
            id: new WorkflowId('command.app.import-catalog'),
            type: WorkflowType::commands(),
            title: 'Catalog import',
            generated: new Generation(new \DateTimeImmutable('2026-09-10T09:00:00+02:00'), 'devtools 0.1.0', GenerationMode::NoAi),
            vcs: VcsState::none(),
            main: ModelFixtures::commandWorkflow()->main,
            files: [
                new TrackedFile(new FileRef('src/Command/ImportCatalogCommand.php'), self::HASH_A),
                new TrackedFile(new FileRef('src/Controller/OrderController.php', FileRole::Controller), self::HASH_A),
            ],
            tests: [new FileRef('tests/Controller/OrderControllerTest.php', FileRole::Test)],
            confidence: Confidence::Medium,
            producer: new WorkflowSource('claude'),
            status: TrackingStatus::Manual,
            history: [new Revision(new \DateTimeImmutable('2026-09-10T09:00:00+02:00'), null, 'initial')],
        );
    }
}
