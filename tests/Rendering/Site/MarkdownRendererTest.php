<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Tests\Rendering\Site;

use Jul6Art\DevTools\Rendering\Site\MarkdownRenderer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MarkdownRenderer::class)]
final class MarkdownRendererTest extends TestCase
{
    public function testThePagesMarkdownBecomesHtml(): void
    {
        $markdown = <<<'MD'
            # GET /orders
            `route.order.index` · type: routes

            ## Trigger

            | Element | Value |
            |---|---|
            | Security | `ROLE_USER` \| `ROLE_ADMIN` |

            Lists the **orders** of *one* customer, see [the detail](order.show.md#journey) and [`route.health`](health.md).
            A second line of the same paragraph.

            - first
              continued
            - second

            1. one
            2. two

            > a quote

            ```mermaid
            flowchart LR
              a["x"] --> b
            ```

            ```xml
            <stack/>
            ```

            <!-- a comment the reader never sees -->
            ---
            MD;
        $html = new MarkdownRenderer(static fn (string $target): string => str_replace('.md', '.html', $target))->render($markdown);

        self::assertStringContainsString('<h1 id="get-orders">GET /orders</h1>', $html);
        self::assertStringContainsString('<p><code>route.order.index</code> · type: routes</p>', $html);
        self::assertStringContainsString('<table><thead><tr><th>Element</th><th>Value</th></tr></thead><tbody><tr><td>Security</td><td><code>ROLE_USER</code> | <code>ROLE_ADMIN</code></td></tr></tbody></table>', $html);
        self::assertStringContainsString('<p>Lists the <strong>orders</strong> of <em>one</em> customer, see <a href="order.show.html#journey">the detail</a> and <a href="health.html"><code>route.health</code></a>. A second line of the same paragraph.</p>', $html);
        self::assertStringContainsString('<ul><li>first continued</li><li>second</li></ul>', $html);
        self::assertStringContainsString('<ol><li>one</li><li>two</li></ol>', $html);
        self::assertStringContainsString('<blockquote><p>a quote</p></blockquote>', $html);
        self::assertStringContainsString("<pre class=\"mermaid\">flowchart LR\n  a[&quot;x&quot;] --&gt; b</pre>", $html);
        self::assertStringContainsString('<pre><code class="language-xml">&lt;stack/&gt;</code></pre>', $html);
        self::assertStringContainsString('<hr>', $html);
        self::assertStringNotContainsString('comment', $html);
    }

    public function testNothingAPageHoldsRunsInTheSite(): void
    {
        $html = new MarkdownRenderer(static fn (string $target): string => $target)->render("A <script>alert(1)</script> and <img src=x onerror=alert(1)>.\n\n[click](javascript:alert(1))\n\n| <b>x</b> |\n|---|\n");

        self::assertStringNotContainsString('<script', $html);
        self::assertStringNotContainsString('<img', $html);
        self::assertStringNotContainsString('<b>', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
    }

    public function testALinkTheSiteDoesNotHoldKeepsItsTextOnly(): void
    {
        $html = new MarkdownRenderer(static fn (string $target): ?string => null)->render('See [the controller](../../src/Controller/OrderController.php).');

        self::assertSame("<p>See the controller.</p>\n", $html);
        self::assertSame('GET /orders', MarkdownRenderer::title("intro\n# GET `/orders`\n"));
        self::assertNull(MarkdownRenderer::title('no title'));
    }
}
