<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Tests\Config;

use Jul6Art\DevTools\Config\Config;
use Jul6Art\DevTools\Config\InvalidConfig;
use Jul6Art\DevTools\Config\XmlConfigReader;
use Jul6Art\DevTools\Inspection\Model\WorkflowType;
use Jul6Art\DevTools\Resources;
use Jul6Art\DevTools\Tests\Support\UsesTemporaryDirectory;
use Jul6Art\DevTools\Xml\InvalidXml;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(XmlConfigReader::class)]
#[CoversClass(Config::class)]
#[CoversClass(InvalidConfig::class)]
final class XmlConfigReaderTest extends TestCase
{
    use UsesTemporaryDirectory;

    public function testAMissingFileMeansTheDefaults(): void
    {
        self::assertEquals(new Config(), new XmlConfigReader()->read($this->temporaryDirectory().'/config.xml'));
    }

    /**
     * The file `init` writes is the one most projects will keep: it must read back as the defaults.
     */
    public function testTheShippedDefaultFileReadsAsTheDefaults(): void
    {
        self::assertEquals(new Config(), new XmlConfigReader()->read(Resources::path('templates/config.xml')));
    }

    public function testEveryOptionIsRead(): void
    {
        $path = $this->temporaryDirectory().'/config.xml';
        file_put_contents($path, <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <devtools xmlns="https://github.com/jul6art/devtools/schema/config/1" schema-version="1">
              <symfony console="docker compose exec -T php bin/console" env="test"/>
              <php web-root="/htdocs/"/>
              <paths><exclude>public/build</exclude><exclude>vendor</exclude></paths>
              <graph depth="2"/>
              <identifiers route-prefix="admin_"/>
              <aliases><alias entrypoint="order_new" id="route.legacy.order.new"/></aliases>
              <groups><group main="app_order_index"><satellite>app_order_export</satellite></group></groups>
              <types><type name="webhooks" prefix="webhook"/></types>
              <language pages="en"/>
            </devtools>
            XML);

        $config = new XmlConfigReader()->read($path);

        self::assertContains('public/build', $config->excludes());
        self::assertContains('node_modules', $config->excludes(), 'Configured exclusions add to the defaults, never replace them.');
        self::assertSame(1, array_count_values($config->excludes())['vendor']);
        self::assertSame(2, $config->graphDepth);
        self::assertSame('admin_', $config->routePrefix);
        self::assertSame(['order_new' => 'route.legacy.order.new'], $config->aliases);
        self::assertSame(['app_order_index' => ['app_order_export']], $config->groups);
        self::assertEquals([WorkflowType::custom('webhooks', 'webhook')], $config->customTypes);
        self::assertSame('docker compose exec -T php bin/console', $config->symfonyConsole);
        self::assertSame('test', $config->symfonyEnv);
        self::assertSame('htdocs', $config->phpWebRoot);
        self::assertSame('en', $config->pagesLanguage);
    }

    public function testAnInvalidFileIsRejectedWithItsLine(): void
    {
        $path = $this->temporaryDirectory().'/config.xml';
        file_put_contents($path, "<?xml version=\"1.0\"?>\n<devtools xmlns=\"https://github.com/jul6art/devtools/schema/config/1\" schema-version=\"1\">\n  <graph depth=\"deep\"/>\n</devtools>\n");

        $this->expectException(InvalidXml::class);
        $this->expectExceptionMessageMatches('/config\.xml, line 3/');

        new XmlConfigReader()->read($path);
    }

    public function testAnAliasToAMalformedIdentifierIsRejected(): void
    {
        $this->expectException(InvalidConfig::class);

        new Config(aliases: ['order_new' => 'Route.Order']);
    }
}
