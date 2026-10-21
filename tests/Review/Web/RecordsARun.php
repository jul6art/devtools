<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Tests\Review\Web;

use Jul6Art\DevTools\Scenario\Run\RunResult;
use Jul6Art\DevTools\Scenario\Run\ScenarioResult;
use Jul6Art\DevTools\Scenario\Run\StepResult;
use Jul6Art\DevTools\Scenario\Run\Verdict;
use Jul6Art\DevTools\Scenario\Run\XmlRunWriter;
use Jul6Art\DevTools\Scenario\Visual\VisualResult;
use Jul6Art\DevTools\Scenario\Visual\VisualVerdict;
use Symfony\Component\Filesystem\Filesystem;

/**
 * A run of symfony-minimal as `scenarios:run` writes it, without a browser: one capture compared with its baseline,
 * one failure, and a captured page whose scripts would write in the page that shows it — if they ran.
 */
trait RecordsARun
{
    private const string RUN = '2026-10-20-100000';

    private const string CAPTURE = 'route.order.new/happy-path/after-load';

    private const string PAGE = <<<'HTML'
        <!DOCTYPE html>
        <html><head><title>New order</title></head>
        <body>
        <script>parent.document.body.setAttribute('data-pwned', 'script');</script>
        <img src="data:," onerror="parent.document.body.setAttribute('data-pwned', 'handler')" alt="">
        <main id="main">
          <form name="order">
            <select id="order_product" name="order[product]"></select>
            <input name="order[customer]">
            <input name="order[note]"><input name="order[note]">
            <button data-action="save">Save</button>
            <button data-action="save" data-role="primary">Save and go on</button>
            <p>First</p><p>Second</p>
          </form>
          <div><span>Total</span></div>
        </main>
        <aside><div><span>Help</span></div></aside>
        <footer><p>One</p><p>Two</p></footer>
        </body></html>
        HTML;

    private function recordRun(string $project): string
    {
        $artifacts = $project.'/.devtools/runs/'.self::RUN;
        $filesystem = new Filesystem();
        $filesystem->dumpFile($artifacts.'/'.self::CAPTURE.'.html', self::PAGE);
        $filesystem->dumpFile($artifacts.'/'.self::CAPTURE.'.png', (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==', true));
        $filesystem->dumpFile($artifacts.'/'.self::CAPTURE.'.diff.png', 'diff');
        $filesystem->dumpFile($artifacts.'/route.order.index/smoke/01-goto.png', 'failure');
        $filesystem->dumpFile($artifacts.'/route.order.index/smoke/01-goto.html', '<p>500</p>');
        $filesystem->dumpFile($project.'/.devtools/baselines/'.self::CAPTURE.'.png', 'baseline');
        new XmlRunWriter()->write($artifacts.'/result.xml', new RunResult(new \DateTimeImmutable('2026-10-20T10:00:00+02:00'), 'http://app.localhost', [
            new ScenarioResult('route.order.new/happy-path', [
                new StepResult(1, 'goto', Verdict::Passed),
                new StepResult(2, 'screenshot', Verdict::Passed, screenshot: self::CAPTURE.'.png', dom: self::CAPTURE.'.html', visual: new VisualResult(VisualVerdict::WithinThreshold, 0.02, self::CAPTURE.'.diff.png')),
            ]),
            new ScenarioResult('route.order.index/smoke', [new StepResult(1, 'goto', Verdict::Failed, message: 'HTTP 500', screenshot: 'route.order.index/smoke/01-goto.png', dom: 'route.order.index/smoke/01-goto.html')]),
        ]));

        return $artifacts;
    }
}
