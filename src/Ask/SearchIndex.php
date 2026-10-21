<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Ask;

/**
 * The full-text index of the pages (ADR-0039): for each workflow, the weighted count of its words, and what
 * a selection widens to — ranked with BM25, implemented here, without a dependency.
 *
 * Kept as JSON in `.devtools/search.idx`, a cache git ignores: never unserialized, read back field by field.
 */
final readonly class SearchIndex
{
    private const float K1 = 1.2;

    private const float B = 0.75;

    /**
     * @param array<string, array{title: string, type: string, page: string, status: string, updated: string, related: list<string>, length: float, terms: array<string, float>}> $documents   by identifier, sorted
     * @param string                                                                                                                                                              $fingerprint what the index was built from
     */
    public function __construct(public array $documents, public string $fingerprint = '')
    {
    }

    /**
     * BM25 of every page against the question, best first; the pages that share no word are left out.
     *
     * @return array<string, float> identifier => score
     */
    public function score(string $question): array
    {
        $terms = array_values(array_unique(Tokenizer::tokens($question)));
        $count = \count($this->documents);

        if ([] === $terms || 0 === $count) {
            return [];
        }

        $average = array_sum(array_column($this->documents, 'length')) / $count ?: 1.0;
        $scores = [];

        foreach ($terms as $term) {
            $containing = array_filter($this->documents, static fn (array $document): bool => isset($document['terms'][$term]));
            $idf = log(1 + ($count - \count($containing) + 0.5) / (\count($containing) + 0.5));

            foreach ($containing as $id => $document) {
                $frequency = $document['terms'][$term];
                $scores[$id] = ($scores[$id] ?? 0.0) + $idf * $frequency * (self::K1 + 1) / ($frequency + self::K1 * (1 - self::B + self::B * $document['length'] / $average));
            }
        }

        // Best first; between equals, the identifier decides, so that a question always gets the same pages.
        uksort($scores, static fn (string $a, string $b): int => [$scores[$b], $a] <=> [$scores[$a], $b]);

        return $scores;
    }

    public function toJson(): string
    {
        return json_encode(['version' => 1, 'fingerprint' => $this->fingerprint, 'documents' => $this->documents], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE)."\n";
    }

    /**
     * The index a previous run wrote, or null when it cannot be read as one — it is then built again.
     */
    public static function fromJson(string $json): ?self
    {
        $data = json_decode($json, true);

        if (!\is_array($data) || 1 !== ($data['version'] ?? null) || !\is_string($data['fingerprint'] ?? null) || !\is_array($data['documents'] ?? null)) {
            return null;
        }

        $documents = [];

        foreach ($data['documents'] as $id => $document) {
            if (!\is_string($id) || !\is_array($document)) {
                return null;
            }

            ['title' => $title, 'type' => $type, 'page' => $page, 'status' => $status, 'updated' => $updated, 'related' => $related, 'terms' => $terms, 'length' => $length] = $document + array_fill_keys(['title', 'type', 'page', 'status', 'updated', 'related', 'terms', 'length'], null);

            if (!\is_string($title) || !\is_string($type) || !\is_string($page) || !\is_string($status) || !\is_string($updated) || !\is_array($related) || !\is_array($terms) || !is_numeric($length)) {
                return null;
            }

            $weights = [];

            foreach ($terms as $term => $weight) {
                if (!is_numeric($weight)) {
                    return null;
                }

                $weights[(string) $term] = (float) $weight;
            }

            $documents[$id] = [
                'title' => $title,
                'type' => $type,
                'page' => $page,
                'status' => $status,
                'updated' => $updated,
                'related' => array_values(array_filter($related, \is_string(...))),
                'length' => (float) $length,
                'terms' => $weights,
            ];
        }

        return new self($documents, $data['fingerprint']);
    }
}
