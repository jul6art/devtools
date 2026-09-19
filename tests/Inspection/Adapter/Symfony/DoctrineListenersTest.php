<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Tests\Inspection\Adapter\Symfony;

use Jul6Art\DevTools\Inspection\Adapter\Symfony\StaticSymfonyScanner;
use Jul6Art\DevTools\Inspection\Graph\PhpReferenceExtractor;
use Jul6Art\DevTools\Project\ProjectRoot;
use Jul6Art\DevTools\Stack\StackProfile;
use Jul6Art\DevTools\Tests\Support\UsesTemporaryDirectory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * ⚠️ A Doctrine listener is registered on Doctrine's own event manager, so `debug:event-dispatcher`
 * never mentions it: only its attribute says it exists. Found on a real project, where deleting one
 * changed nothing at all in the documentation.
 */
#[CoversClass(StaticSymfonyScanner::class)]
final class DoctrineListenersTest extends TestCase
{
    use UsesTemporaryDirectory;

    protected function tearDown(): void
    {
        $this->removeTemporaryDirectory();
    }

    public function testADoctrineListenerIsFoundThroughItsAttribute(): void
    {
        $listeners = $this->scan(<<<'PHP'
            <?php

            namespace App\EventListener;

            use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
            use Doctrine\ORM\Events;

            #[AsDoctrineListener(event: Events::prePersist)]
            #[AsDoctrineListener(event: Events::onFlush)]
            final class TotalsListener
            {
                public function prePersist(): void {}
                public function onFlush(): void {}
            }
            PHP);

        self::assertSame(
            ['onFlush', 'prePersist'],
            self::eventsOf($listeners, 'App\EventListener\TotalsListener'),
            'The value of a Doctrine event constant is its own name.',
        );
    }

    /**
     * ⚠️ Symfony's constants are the other way round: `KernelEvents::REQUEST` is `kernel.request`.
     * Taking the name would document an event nobody listens to.
     */
    public function testAKernelEventConstantIsResolvedToItsValue(): void
    {
        $listeners = $this->scan(<<<'PHP'
            <?php

            namespace App\EventListener;

            use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
            use Symfony\Component\HttpKernel\KernelEvents;

            #[AsEventListener(event: KernelEvents::REQUEST, priority: 12)]
            final class LocaleListener
            {
                public function __invoke(): void {}
            }
            PHP);

        self::assertSame(['kernel.request'], self::eventsOf($listeners, 'App\EventListener\LocaleListener'));
        self::assertSame(12, $listeners['App\EventListener\LocaleListener'][0]['priority']);
    }

    public function testAnUnknownConstantIsNotGuessed(): void
    {
        $listeners = $this->scan(<<<'PHP'
            <?php

            namespace App\EventListener;

            use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

            #[AsEventListener(event: \App\Event\OrderEvents::PLACED)]
            final class OrderListener
            {
                public function __invoke(): void {}
            }
            PHP);

        self::assertArrayNotHasKey('App\EventListener\OrderListener', $listeners, 'Naming an event we cannot resolve would be inventing a fact.');
    }

    /**
     * @return array<string, list<array{event: string, method: string, priority: int|null}>>
     */
    private function scan(string $listener): array
    {
        $project = $this->temporaryDirectory().'/project';
        mkdir($project.'/src/EventListener', 0o777, true);
        file_put_contents($project.'/src/EventListener/Listener.php', $listener);

        $root = new ProjectRoot($project);
        $stack = new StackProfile('.', 'php', 'symfony', '8.1', 'composer', ['src'], [], [], 'symfony', 'symfony-8');

        return new StaticSymfonyScanner($root, $stack, new PhpReferenceExtractor())->listeners();
    }

    /**
     * @param array<string, list<array{event: string, method: string, priority: int|null}>> $listeners
     *
     * @return list<string>
     */
    private static function eventsOf(array $listeners, string $class): array
    {
        $events = array_map(static fn (array $listen): string => $listen['event'], $listeners[$class] ?? []);
        sort($events, \SORT_STRING);

        return $events;
    }
}
