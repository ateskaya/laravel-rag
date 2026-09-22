<?php

namespace IbrahimEnsar\Rag\Answering;

final class Answer
{
    /**
     * @param  list<array{chunk_id: int, document_id: int, title: string, locator: ?string, similarity: float}>  $citations
     */
    private function __construct(
        public readonly string $outcome,
        public readonly ?string $text,
        public readonly array $citations,
        public readonly float $costUsd,
        public readonly int $latencyMs,
        public readonly ?string $reason = null,
    ) {
    }

    public static function answered(string $text, array $citations, float $cost, int $latencyMs): self
    {
        return new self('answered', $text, $citations, $cost, $latencyMs);
    }

    /**
     * Not an error. The retrieved material did not support an answer, and
     * saying so is the correct output.
     */
    public static function refused(string $reason, float $cost, int $latencyMs): self
    {
        return new self('refused', null, [], $cost, $latencyMs, $reason);
    }

    public static function failed(string $reason, float $cost, int $latencyMs): self
    {
        return new self('failed', null, [], $cost, $latencyMs, $reason);
    }

    public function wasAnswered(): bool
    {
        return $this->outcome === 'answered';
    }
}
