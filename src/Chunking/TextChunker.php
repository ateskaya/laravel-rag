<?php

namespace IbrahimEnsar\Rag\Chunking;

use IbrahimEnsar\Rag\Support\TokenCounter;

/**
 * Splits text into retrievable chunks.
 *
 * The naive approach -- slice every N characters -- is what makes most RAG
 * demos answer badly. It cuts sentences in half, so a chunk begins mid-clause
 * and ends mid-clause, and the embedding of that fragment sits nowhere near
 * the embedding of the question it should have answered.
 *
 * So this splits on structure first and falls back to size only when a single
 * paragraph is genuinely too long:
 *
 *   1. Break on blank lines, which is where an author already told you one
 *      idea ended and the next began.
 *   2. Pack consecutive paragraphs together until the target size is reached,
 *      so a document of short paragraphs does not become hundreds of tiny
 *      chunks that each carry too little context to be useful.
 *   3. Split an over-long paragraph on sentence boundaries, and only if a
 *      single sentence still exceeds the target -- a table, a minified
 *      payload, a wall of text with no punctuation -- fall back to a hard cut.
 *   4. Carry an overlap from the end of each chunk into the next, so a fact
 *      that straddles a boundary is retrievable from both sides.
 *
 * Every chunk keeps a locator ("p. 4" or "¶12") so a citation can point at
 * something a human can find in the original.
 */
class TextChunker
{
    public function __construct(
        private readonly int $targetTokens = 800,
        private readonly int $overlapTokens = 120,
        private readonly int $minTokens = 40,
    ) {
    }

    /**
     * @param  array<int, string>  $pages  Optional page-keyed text. When given,
     *                                     locators name the page the chunk
     *                                     started on.
     * @return list<TextChunk>
     */
    public function chunk(string $text, array $pages = []): array
    {
        $segments = $pages !== []
            ? $this->segmentsFromPages($pages)
            : $this->segmentsFromText($text);

        $chunks = [];
        $buffer = '';
        $bufferTokens = 0;
        $bufferLocator = null;
        $position = 0;

        foreach ($segments as $segment) {
            [$paragraph, $locator] = $segment;

            foreach ($this->splitOversizedParagraph($paragraph) as $piece) {
                $pieceTokens = TokenCounter::estimate($piece);

                if ($bufferTokens > 0 && $bufferTokens + $pieceTokens > $this->targetTokens) {
                    $chunks[] = new TextChunk(
                        content: trim($buffer),
                        position: $position++,
                        tokenEstimate: $bufferTokens,
                        locator: $bufferLocator,
                    );

                    $buffer = $this->tailForOverlap($buffer);
                    $bufferTokens = TokenCounter::estimate($buffer);
                    $bufferLocator = $locator;
                }

                if ($bufferTokens === 0) {
                    $bufferLocator = $locator;
                }

                $buffer = $buffer === '' ? $piece : $buffer."\n\n".$piece;
                $bufferTokens += $pieceTokens;
            }
        }

        $tail = trim($buffer);

        if ($tail !== '') {
            $tailTokens = TokenCounter::estimate($tail);

            // A trailing fragment shorter than the floor is appended to the
            // previous chunk rather than stored alone: on its own it is too
            // small to embed meaningfully, and dropping it would lose content.
            if ($tailTokens < $this->minTokens && $chunks !== []) {
                $last = array_pop($chunks);

                $chunks[] = new TextChunk(
                    content: $last->content."\n\n".$tail,
                    position: $last->position,
                    tokenEstimate: $last->tokenEstimate + $tailTokens,
                    locator: $last->locator,
                );
            } else {
                $chunks[] = new TextChunk($tail, $position, $tailTokens, $bufferLocator);
            }
        }

        return $chunks;
    }

    /**
     * @param  array<int, string>  $pages
     * @return list<array{0: string, 1: string}>
     */
    private function segmentsFromPages(array $pages): array
    {
        $segments = [];

        foreach ($pages as $pageNumber => $pageText) {
            foreach ($this->paragraphs($pageText) as $paragraph) {
                $segments[] = [$paragraph, 'p. '.$pageNumber];
            }
        }

        return $segments;
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    private function segmentsFromText(string $text): array
    {
        $segments = [];
        $index = 1;

        foreach ($this->paragraphs($text) as $paragraph) {
            $segments[] = [$paragraph, '¶'.$index++];
        }

        return $segments;
    }

    /**
     * @return list<string>
     */
    private function paragraphs(string $text): array
    {
        $normalised = preg_replace("/\r\n?/", "\n", $text) ?? $text;

        // Collapse runs of three or more newlines so that decorative spacing
        // does not produce empty segments.
        $normalised = preg_replace("/\n{3,}/", "\n\n", $normalised) ?? $normalised;

        $parts = preg_split("/\n{2,}/", $normalised) ?: [];

        return array_values(array_filter(
            array_map(static fn (string $p): string => trim($p), $parts),
            static fn (string $p): bool => $p !== '',
        ));
    }

    /**
     * @return list<string>
     */
    private function splitOversizedParagraph(string $paragraph): array
    {
        if (TokenCounter::estimate($paragraph) <= $this->targetTokens) {
            return [$paragraph];
        }

        $sentences = preg_split('/(?<=[.!?…])\s+/u', $paragraph, -1, PREG_SPLIT_NO_EMPTY) ?: [$paragraph];

        $pieces = [];
        $current = '';

        foreach ($sentences as $sentence) {
            // A single "sentence" over the target is not prose: a table row, a
            // base64 blob, a language this regex cannot segment. Cut it hard
            // rather than emitting a chunk the model cannot fit.
            if (TokenCounter::estimate($sentence) > $this->targetTokens) {
                if (trim($current) !== '') {
                    $pieces[] = trim($current);
                    $current = '';
                }

                foreach ($this->hardSplit($sentence) as $slice) {
                    $pieces[] = $slice;
                }

                continue;
            }

            if ($current !== '' && TokenCounter::estimate($current.' '.$sentence) > $this->targetTokens) {
                $pieces[] = trim($current);
                $current = $sentence;

                continue;
            }

            $current = $current === '' ? $sentence : $current.' '.$sentence;
        }

        if (trim($current) !== '') {
            $pieces[] = trim($current);
        }

        return $pieces;
    }

    /**
     * @return list<string>
     */
    private function hardSplit(string $text): array
    {
        $size = TokenCounter::charsFor($this->targetTokens);
        $slices = [];
        $length = mb_strlen($text);

        for ($offset = 0; $offset < $length; $offset += $size) {
            $slice = trim(mb_substr($text, $offset, $size));

            if ($slice !== '') {
                $slices[] = $slice;
            }
        }

        return $slices;
    }

    /**
     * Take the last `overlapTokens` worth of text, rounded back to a sentence
     * boundary where one is close enough, so the overlap reads as language
     * rather than as a fragment.
     */
    private function tailForOverlap(string $buffer): string
    {
        if ($this->overlapTokens <= 0) {
            return '';
        }

        $chars = TokenCounter::charsFor($this->overlapTokens);
        $tail = mb_substr($buffer, -$chars);

        if (preg_match('/[.!?…]\s+(.+)$/us', $tail, $matches) === 1) {
            $candidate = trim($matches[1]);

            if ($candidate !== '' && mb_strlen($candidate) > $chars / 4) {
                return $candidate;
            }
        }

        return trim($tail);
    }
}
