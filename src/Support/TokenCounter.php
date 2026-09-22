<?php

namespace IbrahimEnsar\Rag\Support;

/**
 * A deliberately cheap token estimate.
 *
 * Exact counts need the model's own BPE tokenizer, which means either a PHP
 * port that drifts out of date or an extra service call per chunk. Neither is
 * worth it here: these numbers drive chunk sizing and a context budget, and
 * both of those are decisions where being 10% off costs nothing, because the
 * budget is already set below the hard limit.
 *
 * Where exactness does matter -- billing -- the numbers come from the
 * provider's own usage block, never from this class.
 */
final class TokenCounter
{
    /** Empirically close for English prose; Turkish and code run a little denser. */
    private const CHARS_PER_TOKEN = 4.0;

    public static function estimate(string $text): int
    {
        $length = mb_strlen(trim($text));

        if ($length === 0) {
            return 0;
        }

        return (int) max(1, ceil($length / self::CHARS_PER_TOKEN));
    }

    public static function charsFor(int $tokens): int
    {
        return (int) floor($tokens * self::CHARS_PER_TOKEN);
    }
}
