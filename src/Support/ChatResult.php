<?php

namespace IbrahimEnsar\Rag\Support;

final class ChatResult
{
    public function __construct(
        public readonly string $content,
        public readonly int $promptTokens,
        public readonly int $completionTokens,
        public readonly string $model,
        public readonly ?string $finishReason = null,
    ) {
    }

    /**
     * True when the model stopped because it hit the output ceiling rather than
     * because it had finished. The caller should treat the answer as truncated.
     */
    public function wasTruncated(): bool
    {
        return $this->finishReason === 'length';
    }
}
