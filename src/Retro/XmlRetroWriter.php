<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Retro;

use Jul6Art\DevTools\Resources;
use Jul6Art\DevTools\Xml\DomBuilder;
use Jul6Art\DevTools\Xml\SafeXmlLoader;

/**
 * The retrospective for a tool to read (`resources/schemas/retro.xsd`).
 */
final readonly class XmlRetroWriter
{
    public const string NAMESPACE = 'https://github.com/jul6art/devtools/schema/retro/1';

    private DomBuilder $dom;

    public function __construct(private SafeXmlLoader $loader = new SafeXmlLoader())
    {
        $this->dom = new DomBuilder(self::NAMESPACE);
    }

    public function serialize(RetroReport $report): string
    {
        $xml = $this->dom->document();
        $root = $this->dom->element($xml, 'retro', [
            'schema-version' => '1',
            'since' => $report->since->format(\DATE_ATOM),
            'until' => $report->until->format(\DATE_ATOM),
            'unattributed' => (string) $report->unattributed,
            'inactive-days' => (string) $report->inactiveDays,
        ]);

        foreach ($report->months as $month) {
            $perTask = RetroReport::perTask($month);
            $this->dom->element($root, 'month', array_filter(['month' => $month['month'], 'tasks' => (string) $month['tasks'], 'human' => (string) $month['human'], 'per-task' => null === $perTask ? null : self::decimal($perTask)], \is_string(...)));
        }

        $trend = $report->trend();

        if (null !== $trend) {
            $this->dom->element($root, 'trend', ['month' => $trend['month'], 'per-task' => self::decimal($trend['perTask']), 'before' => self::decimal($trend['before']), 'change' => (string) $trend['change']]);
        }

        foreach ($report->categories as $category) {
            $this->dom->element($root, 'category', ['name' => $category['category'], 'recent' => (string) $category['recent'], 'before' => self::decimal($category['before']), 'trend' => $category['trend']]);
        }

        foreach ($report->blocking as $rule) {
            $this->dom->element($root, 'blocking', ['rule' => $rule['rule'], 'tasks' => (string) $rule['tasks'], 'blocked' => (string) $rule['blocked']]);
        }

        foreach ($report->inactive as $rule) {
            $this->dom->element($root, 'inactive', ['rule' => $rule['rule'], 'last' => $rule['last'], 'days' => (string) $rule['days']]);
        }

        foreach ($report->unpromoted as $feedback) {
            $this->dom->element($root, 'unpromoted', ['feedback' => $feedback['id'], 'occurrences' => (string) $feedback['occurrences'], 'summary' => $feedback['summary']]);
        }

        $passes = $this->dom->element($root, 'passes', ['tasks' => (string) $report->tasks, 'attempts' => (string) $report->attempts]);

        foreach ($report->passes as $task) {
            $element = $this->dom->element($passes, 'task', ['id' => $task['task'], 'attempts' => (string) $task['attempts']]);

            foreach ($task['steps'] as $step) {
                $this->dom->element($element, 'failed', ['step' => $step]);
            }
        }

        foreach ($report->verifier as $category) {
            $this->dom->element($root, 'source', ['category' => $category['category'], 'verifier' => (string) $category['verifier'], 'human' => (string) $category['human']]);
        }

        $document = $this->dom->toXml($xml);
        $this->loader->validate($this->loader->load($document, 'retro'), Resources::path('schemas/retro.xsd'), 'retro');

        return $document;
    }

    private static function decimal(float $value): string
    {
        return number_format($value, 2, '.', '');
    }
}
