<?php

namespace IbrahimEnsar\Rag\Answering;

use IbrahimEnsar\Rag\Retrieval\RetrievedChunk;

/**
 * Decides what a model's reply is actually worth, given the sources it was shown.
 *
 * This is the step that turns "the prompt asked it to cite" into "every citation
 * that survives was checked". It has no dependencies on Laravel, the database or
 * the provider, so the rules that matter most can be tested in isolation.
 *
 * Four outcomes:
 *
 *  - answered     prose plus at least one citation that points at a real source
 *  - insufficient the model said, in the agreed shape, that the sources don't cover it
 *  - ungrounded   the model produced prose, but nothing it cited was in front of it
 *  - unparseable  the body was not the agreed JSON shape at all
 */
final class GroundingCheck
{
    public const ANSWERED = 'answered';
    public const INSUFFICIENT = 'insufficient';
    public const UNGROUNDED = 'ungrounded';
    public const UNPARSEABLE = 'unparseable';

    /**
     * @param  list<array{chunk_id: int, document_id: int, title: string, locator: ?string, similarity: float}>  $citations
     */
    private function __construct(
        public readonly string $outcome,
        public readonly ?string $text,
        public readonly array $citations,
    ) {
    }

    /**
     * @param  list<RetrievedChunk>  $chunks  the sources, in the order they were numbered in the prompt
     */
    public static function evaluate(string $body, array $chunks): self
    {
        $decoded = json_decode($body, true);

        if (! is_array($decoded) || ! isset($decoded['answer'])) {
            return new self(self::UNPARSEABLE, null, []);
        }

        if (($decoded['sufficient'] ?? true) === false) {
            $text = is_string($decoded['answer']) && trim($decoded['answer']) !== ''
                ? $decoded['answer']
                : null;

            return new self(self::INSUFFICIENT, $text, []);
        }

        $citations = self::validCitations($decoded['citations'] ?? [], $chunks);

        if ($citations === []) {
            // Prose with nothing traceable behind it is the shape an invented
            // answer takes. It is not returned.
            return new self(self::UNGROUNDED, null, []);
        }

        return new self(self::ANSWERED, (string) $decoded['answer'], $citations);
    }

    public function isAnswered(): bool
    {
        return $this->outcome === self::ANSWERED;
    }

    /**
     * @param  list<RetrievedChunk>  $chunks
     * @return list<array{chunk_id: int, document_id: int, title: string, locator: ?string, similarity: float}>
     */
    private static function validCitations(mixed $raw, array $chunks): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $citations = [];

        foreach ($raw as $number) {
            if (! is_int($number) && ! (is_string($number) && ctype_digit($number))) {
                continue;
            }

            $index = ((int) $number) - 1;

            // The bounds check is the whole point: a hallucinated [9] against
            // six sources lands here and is discarded.
            if (! isset($chunks[$index])) {
                continue;
            }

            $chunk = $chunks[$index];

            // Keyed by chunk so a source cited twice is reported once.
            $citations[$chunk->chunkId] = [
                'chunk_id' => $chunk->chunkId,
                'document_id' => $chunk->documentId,
                'title' => $chunk->documentTitle,
                'locator' => $chunk->locator,
                'similarity' => $chunk->similarity(),
            ];
        }

        return array_values($citations);
    }
}
