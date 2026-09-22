<?php

namespace IbrahimEnsar\Rag\Retrieval;

final class RetrievedChunk
{
    public function __construct(
        public readonly int $chunkId,
        public readonly int $documentId,
        public readonly string $documentTitle,
        public readonly string $content,
        public readonly ?string $locator,
        public readonly float $distance,
        public readonly int $tokenEstimate,
    ) {
    }

    /** Cosine distance runs 0 (identical) to 2 (opposite); 0..1 covers anything useful here. */
    public function similarity(): float
    {
        return round(1.0 - $this->distance, 4);
    }
}
