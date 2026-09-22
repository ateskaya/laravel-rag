<?php

namespace IbrahimEnsar\Rag\Support;

final class EmbeddingResult
{
    /**
     * @param  list<list<float>>  $vectors  One vector per input, in input order.
     */
    public function __construct(
        public readonly array $vectors,
        public readonly int $promptTokens,
        public readonly string $model,
    ) {
    }

    public function first(): array
    {
        return $this->vectors[0] ?? [];
    }
}
