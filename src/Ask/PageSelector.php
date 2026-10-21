<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Ask;

/**
 * The pages that answer a question (ADR-0039), without AI: the best of BM25 — those within a quarter of the
 * best score, so that a word met by chance does not bring a page — then, one hop away from the three best,
 * the workflows they depend on or lead to. Eight at most: what a reader, or a model, reads in full.
 */
final class PageSelector
{
    public const int LIMIT = 8;

    private const float RELEVANT = 0.25;

    private const int WIDENED_FROM = 3;

    /**
     * @return list<SelectedPage> the pages found first, best first, then those they lead to
     */
    public function select(SearchIndex $index, string $question, int $limit = self::LIMIT): array
    {
        $scores = $index->score($question);
        $best = array_first($scores);

        if (null === $best) {
            return [];
        }

        $selected = [];

        foreach ($scores as $id => $score) {
            if ($score < $best * self::RELEVANT || \count($selected) >= $limit) {
                break;
            }

            $selected[$id] = self::page($index, $id, $score);
        }

        foreach (\array_slice(array_keys($selected), 0, self::WIDENED_FROM) as $from) {
            foreach ($index->documents[$from]['related'] as $related) {
                if (\count($selected) < $limit && !isset($selected[$related]) && isset($index->documents[$related])) {
                    $selected[$related] = self::page($index, $related, $scores[$related] ?? 0.0, $from);
                }
            }
        }

        return array_values($selected);
    }

    private static function page(SearchIndex $index, string $id, float $score, ?string $via = null): SelectedPage
    {
        $document = $index->documents[$id];

        return new SelectedPage($id, $document['title'], $document['page'], $score, $document['status'], $document['updated'], $via);
    }
}
