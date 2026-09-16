<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Tests\Inspection\Model\Serialization;

use Jul6Art\DevTools\Inspection\Model\FileRef;
use Jul6Art\DevTools\Inspection\Model\InspectionResult;
use Jul6Art\DevTools\Inspection\Model\InvalidModel;
use Jul6Art\DevTools\Inspection\Model\Serialization\ModelXmlSerializer;
use Jul6Art\DevTools\Inspection\Model\WorkflowTypeRegistry;
use Jul6Art\DevTools\Tests\Inspection\Model\ModelFixtures;
use Jul6Art\DevTools\Tests\Support\AssertsSnapshots;
use Jul6Art\DevTools\Xml\InvalidXml;
use Jul6Art\DevTools\Xml\SafeXmlLoader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The XML form of the model is what Claude writes on the discovery path (ADR-0013) and what the
 * snapshots compare. Two properties matter above all: it survives a round trip unchanged, and the
 * same model always produces the same bytes.
 */
#[CoversClass(ModelXmlSerializer::class)]
#[CoversClass(SafeXmlLoader::class)]
#[CoversClass(InvalidXml::class)]
final class ModelXmlSerializerTest extends TestCase
{
    use AssertsSnapshots;

    public function testARoundTripRestoresAnIdenticalModel(): void
    {
        $serializer = new ModelXmlSerializer(new WorkflowTypeRegistry());
        $result = $this->inspectionResult();

        $xml = $serializer->serialize($result);

        self::assertEquals($result, $serializer->deserialize($xml));
        self::assertSame($xml, $serializer->serialize($serializer->deserialize($xml)));
    }

    public function testTheSameModelBuiltInAnotherOrderSerialisesToTheSameBytes(): void
    {
        $serializer = new ModelXmlSerializer(new WorkflowTypeRegistry());

        $forward = new InspectionResult('symfony-7', [ModelFixtures::orderNewWorkflow(), ModelFixtures::commandWorkflow()], [new FileRef('src/A.php'), new FileRef('src/B.php')]);
        $backward = new InspectionResult('symfony-7', [ModelFixtures::commandWorkflow(), ModelFixtures::orderNewWorkflow()], [new FileRef('src/B.php'), new FileRef('src/A.php')]);

        self::assertSame($serializer->serialize($forward), $serializer->serialize($backward));
    }

    public function testTheSerialisedFormMatchesTheSnapshot(): void
    {
        $xml = new ModelXmlSerializer(new WorkflowTypeRegistry())->serialize($this->inspectionResult());

        self::assertMatchesSnapshot($xml, __DIR__.'/Fixtures/inspection-model.xml');
    }

    public function testItAcceptsADocumentWithoutTheOptionalContainers(): void
    {
        $result = new ModelXmlSerializer(new WorkflowTypeRegistry())->deserialize(<<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <inspection xmlns="https://github.com/jul6art/devtools/schema/inspection-model/1" schema-version="1" stack="express-4">
              <workflows>
                <workflow id="route.get.orders" type="routes" confidence="medium" source="claude">
                  <title>Order list</title>
                  <main kind="route" name="GET /orders">
                    <declared-in path="src/routes/orders.js" role="other"/>
                  </main>
                </workflow>
              </workflows>
            </inspection>
            XML);

        self::assertSame('route.get.orders', (string) $result->workflows[0]->id);
        self::assertSame([], $result->uncovered);
    }

    public function testASchemaViolationIsRejectedWithItsLine(): void
    {
        $xml = str_replace('confidence="high"', 'confidence="absolute"', new ModelXmlSerializer(new WorkflowTypeRegistry())->serialize($this->inspectionResult()));
        $line = substr_count(strstr($xml, 'confidence="absolute"', true) ?: '', "\n") + 1;

        $this->expectException(InvalidXml::class);
        $this->expectExceptionMessageMatches(\sprintf('/line %d\b/', $line));

        new ModelXmlSerializer(new WorkflowTypeRegistry())->deserialize($xml, 'discovery.draft.xml');
    }

    public function testAModelInvariantViolatedInTheDocumentIsRejected(): void
    {
        $xml = str_replace('path="src/Form/OrderType.php"', 'path="../../etc/passwd"', new ModelXmlSerializer(new WorkflowTypeRegistry())->serialize($this->inspectionResult()));

        $this->expectException(InvalidModel::class);
        $this->expectExceptionMessage('outside the project root');

        new ModelXmlSerializer(new WorkflowTypeRegistry())->deserialize($xml);
    }

    public function testAnUnknownTypeIsRejected(): void
    {
        $xml = str_replace('type="commands"', 'type="webhooks"', new ModelXmlSerializer(new WorkflowTypeRegistry())->serialize($this->inspectionResult()));

        $this->expectException(InvalidModel::class);
        $this->expectExceptionMessage('"webhooks"');

        new ModelXmlSerializer(new WorkflowTypeRegistry())->deserialize($xml);
    }

    public function testMalformedXmlIsRejectedWithItsLine(): void
    {
        $this->expectException(InvalidXml::class);
        $this->expectExceptionMessageMatches('/draft\.xml.*line 3\b/');

        new ModelXmlSerializer(new WorkflowTypeRegistry())->deserialize("<?xml version=\"1.0\"?>\n<inspection>\n<workflows>\n</inspection>\n", 'draft.xml');
    }

    /**
     * The document comes from an analysed repository or from Claude: a DOCTYPE is refused outright,
     * before any entity can be resolved.
     */
    public function testADoctypeIsRefusedBeforeAnyEntityIsResolved(): void
    {
        $secret = tempnam(sys_get_temp_dir(), 'devtools-xxe-');
        self::assertIsString($secret);
        file_put_contents($secret, 'TOP-SECRET-CONTENT');

        try {
            new ModelXmlSerializer(new WorkflowTypeRegistry())->deserialize(\sprintf(<<<'XML'
                <?xml version="1.0"?>
                <!DOCTYPE inspection [<!ENTITY secret SYSTEM "file://%s">]>
                <inspection xmlns="https://github.com/jul6art/devtools/schema/inspection-model/1" schema-version="1" stack="&secret;"><workflows/></inspection>
                XML, $secret));
            self::fail('A DOCTYPE must be refused.');
        } catch (InvalidXml $invalid) {
            self::assertStringContainsString('DOCTYPE', $invalid->getMessage());
            self::assertStringNotContainsString('TOP-SECRET-CONTENT', $invalid->getMessage());
        } finally {
            unlink($secret);
        }
    }

    public function testTheLoaderLeavesTheLibxmlErrorModeAsItFoundIt(): void
    {
        $before = libxml_use_internal_errors(false);

        try {
            new SafeXmlLoader()->load('<broken', 'x.xml');
        } catch (InvalidXml) {
        }

        self::assertFalse(libxml_use_internal_errors($before));
    }

    private function inspectionResult(): InspectionResult
    {
        return new InspectionResult(
            'symfony-7',
            [ModelFixtures::orderNewWorkflow(), ModelFixtures::commandWorkflow()],
            [new FileRef('src/Util/StringHelper.php')],
        );
    }
}
