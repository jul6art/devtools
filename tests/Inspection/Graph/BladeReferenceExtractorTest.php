<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Tests\Inspection\Graph;

use Jul6Art\DevTools\Inspection\Graph\BladeReferenceExtractor;
use Jul6Art\DevTools\Inspection\Graph\BladeReferences;
use Jul6Art\DevTools\Inspection\Graph\PhpReferenceExtractor;
use Jul6Art\DevTools\Tests\Inspection\Adapter\Laravel\LaravelAdapterTest;
use Jul6Art\DevTools\Tests\Support\UsesTemporaryDirectory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(BladeReferenceExtractor::class)]
#[CoversClass(BladeReferences::class)]
#[CoversClass(PhpReferenceExtractor::class)]
final class BladeReferenceExtractorTest extends TestCase
{
    use UsesTemporaryDirectory;

    public function testAViewNamesItsLayoutItsIncludesItsComponentsAndItsLinks(): void
    {
        $view = $this->temporaryDirectory().'/page.blade.php';
        file_put_contents($view, <<<'BLADE'
            @extends('layouts.app')
            @section('content')
                @include('orders._row', ['order' => $order])
                @includeIf('partials.banner')
                @includeWhen($admin, 'partials.admin')
                @include($dynamic)
                <x-alert type="info"/>
                <x-forms.text-input name="reference"/>
                <x-slot:title>Orders</x-slot:title>
                <x-slot name="footer"/>
                <livewire:cart-summary/>
                @livewire('admin.users-table')
                @include('mail::message')
                <a href="{{ route('orders.show', $order) }}">Show</a>
            @endsection
            BLADE);

        $references = new BladeReferenceExtractor()->extract($view);

        self::assertSame(['layouts/app.blade.php'], $references->parents);
        self::assertSame([
            'components/alert.blade.php',
            'components/forms/text-input.blade.php',
            'layouts/app.blade.php',
            'orders/_row.blade.php',
            'partials/admin.blade.php',
            'partials/banner.blade.php',
        ], $references->views, 'A view built at runtime is not seen, and a package\'s view is not the project\'s.');
        self::assertSame(['App\Livewire\Admin\UsersTable', 'App\Livewire\CartSummary', 'App\View\Components\Alert', 'App\View\Components\Forms\TextInput'], $references->classes);
        self::assertSame(['orders.show'], $references->routes);
    }

    public function testAViewIsReadOncePerScan(): void
    {
        $view = $this->temporaryDirectory().'/page.blade.php';
        file_put_contents($view, "@include('a')");
        $extractor = new BladeReferenceExtractor();
        $first = $extractor->extract($view);
        file_put_contents($view, "@include('b')");

        self::assertSame($first, $extractor->extract($view));
    }

    public function testLaravelHelpersRenderViewsAndLeadToRoutes(): void
    {
        $controllers = LaravelAdapterTest::PROJECT.'/app/Http/Controllers';
        $extractor = new PhpReferenceExtractor();

        self::assertSame(['orders/index.blade.php'], $extractor->extract($controllers.'/OrderController.php', ['index'])->templates);
        self::assertSame(['orders.show'], $extractor->extract($controllers.'/OrderController.php', ['store'])->routes, 'redirect()->route()');
        self::assertSame(['about.blade.php'], $extractor->extract($controllers.'/AboutController.php')->templates, 'View::make()');

        $helpers = $this->temporaryDirectory().'/Helpers.php';
        file_put_contents($helpers, "<?php\nfunction a() { return to_route('orders.index'); }\nfunction b() { return route('home'); }\nfunction c() { return view('mail::layout'); }\n");

        self::assertSame(['home', 'orders.index'], $extractor->extract($helpers)->routes);
        self::assertSame([], $extractor->extract($helpers)->templates);
    }
}
