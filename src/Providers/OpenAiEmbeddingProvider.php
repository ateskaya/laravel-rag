<?php

namespace IbrahimEnsar\Rag\Providers;

use IbrahimEnsar\Rag\Contracts\EmbeddingProvider;
use IbrahimEnsar\Rag\Exceptions\ProviderFailed;
use IbrahimEnsar\Rag\Support\EmbeddingResult;

class OpenAiEmbeddingProvider implements EmbeddingProvider
{
    public function __construct(
        private readonly OpenAiClient $client,
        private readonly string $model,
        private readonly int $dimensions,
    ) {
    }

    public function embed(array $texts): EmbeddingResult
    {
        if ($texts === []) {
            throw new ProviderFailed('Refusing to embed an empty batch.');
        }

        $response = $this->client->post('embeddings', [
            'model' => $this->model,
            'input' => array_values($texts),
            'dimensions' => $this->dimensions,
        ]);

        $data = $response['data'] ?? null;

        if (! is_array($data) || count($data) !== count($texts)) {
            throw new ProviderFailed(sprintf(
                'Expected %d embeddings, received %s.',
                count($texts),
                is_array($data) ? (string) count($data) : 'none',
            ));
        }

        // The API documents that results come back in input order, but it also
        // returns an explicit index. Sorting by it costs nothing and removes
        // the possibility of silently pairing a vector with the wrong chunk --
        // a bug that would produce plausible, confidently wrong answers rather
        // than an error.
        usort($data, static fn (array $a, array $b): int => ($a['index'] ?? 0) <=> ($b['index'] ?? 0));

        $vectors = [];

        foreach ($data as $row) {
            $vector = $row['embedding'] ?? null;

            if (! is_array($vector) || count($vector) !== $this->dimensions) {
                throw new ProviderFailed(sprintf(
                    'Expected %d-dimension vectors, received %s.',
                    $this->dimensions,
                    is_array($vector) ? (string) count($vector) : 'a non-array',
                ));
            }

            $vectors[] = array_map(static fn ($v): float => (float) $v, $vector);
        }

        return new EmbeddingResult(
            vectors: $vectors,
            promptTokens: (int) ($response['usage']['prompt_tokens'] ?? 0),
            model: (string) ($response['model'] ?? $this->model),
        );
    }

    public function model(): string
    {
        return $this->model;
    }

    public function dimensions(): int
    {
        return $this->dimensions;
    }
}
