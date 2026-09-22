<?php

namespace IbrahimEnsar\Rag\Support;

use InvalidArgumentException;

/**
 * pgvector's wire format is a bracketed, comma-separated list: [0.1,0.2,...].
 * Binding a PHP array to that column silently fails, so every write goes
 * through here.
 */
final class Vector
{
    /**
     * @param  list<float>  $values
     */
    public static function toLiteral(array $values): string
    {
        if ($values === []) {
            throw new InvalidArgumentException('Refusing to store an empty vector.');
        }

        // JSON_PRESERVE_ZERO_FRACTION keeps 1.0 from being written as "1",
        // which pgvector accepts but which makes stored values harder to diff.
        $parts = array_map(
            static fn (float $v): string => rtrim(rtrim(sprintf('%.8F', $v), '0'), '.') ?: '0',
            $values
        );

        return '['.implode(',', $parts).']';
    }

    /**
     * @return list<float>
     */
    public static function fromLiteral(string $literal): array
    {
        $trimmed = trim($literal, "[] \t\n\r");

        if ($trimmed === '') {
            return [];
        }

        return array_map(static fn (string $v): float => (float) $v, explode(',', $trimmed));
    }
}
