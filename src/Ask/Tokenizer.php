<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Ask;

/**
 * Words as the search index counts them (ADR-0039), the same for a page and for a question: lowercase,
 * without accents, identifiers split (`OrderController`, `route.order.new`, `order_id`), the words that
 * carry no meaning in English or French left out, and reduced to a stem by their longest common suffix —
 * `valide`, `validate`, `validated` and `validation` are one word, `commandes` and `commande` too, while a
 * stem keeps four letters at least: `orders` is `order`, never `ord`.
 */
final class Tokenizer
{
    private const array STOP_WORDS = [
        'a', 'an', 'and', 'are', 'as', 'at', 'be', 'by', 'can', 'do', 'doe', 'does', 'for', 'from', 'happen', 'happens', 'how', 'if', 'in', 'is', 'it', 'its', 'of', 'on', 'or', 'the', 'their', 'this', 'that', 'to', 'what', 'when', 'where', 'which', 'who', 'why', 'with',
        'au', 'aux', 'ce', 'ces', 'cette', 'comment', 'dans', 'de', 'des', 'du', 'elle', 'en', 'est', 'et', 'il', 'la', 'le', 'les', 'leur', 'ou', 'par', 'pas', 'passe', 'pour', 'quand', 'que', 'quel', 'quelle', 'qui', 'quoi', 'sa', 'se', 'ses', 'son', 'sont', 'sur', 'un', 'une',
    ];

    private const array ACCENTS = [
        'à' => 'a', 'â' => 'a', 'ä' => 'a', 'á' => 'a', 'ç' => 'c', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'î' => 'i', 'ï' => 'i', 'í' => 'i',
        'ô' => 'o', 'ö' => 'o', 'ó' => 'o', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ú' => 'u', 'ÿ' => 'y', 'ñ' => 'n', 'œ' => 'oe', 'æ' => 'ae',
    ];

    /**
     * Longest first: the first that leaves a stem of four letters is taken off.
     */
    private const array SUFFIXES = [
        'issements', 'issement', 'ements', 'ement', 'ations', 'ation', 'ateurs', 'ateur', 'atrices', 'atrice', 'ances', 'ance', 'ences', 'ence',
        'ments', 'ment', 'ings', 'ing', 'ated', 'ates', 'ate', 'ions', 'ion', 'ers', 'er', 'ees', 'ee', 'es', 'ed', 'ue', 'e', 's',
    ];

    private const int STEM = 4;

    /**
     * @return list<string>
     */
    public static function tokens(string $text): array
    {
        $text = (string) preg_replace('/(?<=\p{Ll})(?=\p{Lu})/u', ' ', $text);
        $text = strtr(mb_strtolower($text), self::ACCENTS);
        $tokens = [];

        foreach (preg_split('/[^a-z0-9]+/', $text, flags: \PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            if (\strlen($word) > 1 && !ctype_digit($word) && !\in_array($word, self::STOP_WORDS, true)) {
                $tokens[] = self::stem($word);
            }
        }

        return $tokens;
    }

    private static function stem(string $word): string
    {
        foreach (self::SUFFIXES as $suffix) {
            if (str_ends_with($word, $suffix) && \strlen($word) - \strlen($suffix) >= self::STEM && !str_ends_with($word, 'ss')) {
                return substr($word, 0, -\strlen($suffix));
            }
        }

        return $word;
    }
}
