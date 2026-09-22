<?php

namespace IbrahimEnsar\Rag\Chunking;

final class TextChunk
{
    public function __construct(
        public readonly string $content,
        public readonly int $position,
        public readonly int $tokenEstimate,
        public readonly ?string $locator = null,
    ) {
    }
}
