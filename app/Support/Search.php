<?php

namespace App\Support;

/**
 * One alphabet for both sides of the search.
 *
 * Upstream's FTS5 porter tokenizer split on punctuation, so a query stripped to letters and digits
 * still matched a stored "pixel.png". Postgres' to_tsvector keeps "pixel.png" whole as a file token,
 * so the index has to be written through the same filter the query goes through.
 */
final class Search
{
    public static function normalize(?string $text): string
    {
        return trim(preg_replace('/\s+/u', ' ', preg_replace('/[^\p{L}\p{N}_]/u', ' ', (string) $text)));
    }
}
