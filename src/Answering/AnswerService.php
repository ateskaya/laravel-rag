<?php

namespace IbrahimEnsar\Rag\Answering;

use IbrahimEnsar\Rag\Contracts\ChatProvider;
use IbrahimEnsar\Rag\Contracts\EmbeddingProvider;
use IbrahimEnsar\Rag\Exceptions\ProviderFailed;
use IbrahimEnsar\Rag\Models\QueryLog;
use IbrahimEnsar\Rag\Retrieval\RetrievedChunk;
use IbrahimEnsar\Rag\Retrieval\VectorRetriever;
use IbrahimEnsar\Rag\Support\CostMeter;

/**
 * Question in, grounded answer out.
 *
 * The design commitment is that the service would rather say "I don't know"
 * than produce a fluent answer with nothing behind it. Three mechanisms
 * enforce that, and they are layered because each one alone leaks:
 *
 *  1. The retriever drops anything beyond the distance ceiling, so an
 *     unrelated question arrives here with no context at all.
 *  2. The prompt tells the model to answer only from the numbered sources and
 *     to return a specific JSON shape when it cannot.
 *  3. Every citation the model returns is checked against the chunks that were
 *     actually sent. A model that invents source [9] gets that citation
 *     stripped, and an answer left with no valid citations is downgraded to a
 *     refusal rather than returned.
 *
 * Step 3 is the one that does the real work. Instructions alone are a request;
 * validating the output is a guarantee.
 */
class AnswerService
{
    public function __construct(
        private readonly EmbeddingProvider $embedder,
        private readonly ChatProvider $chat,
        private readonly VectorRetriever $retriever,
        private readonly array $pricing,
    ) {
    }

    public function ask(string $collection, string $question): Answer
    {
        $startedAt = microtime(true);
        $meter = new CostMeter($this->pricing);

        $question = trim($question);

        if ($question === '') {
            return $this->log($collection, $question, Answer::failed('The question was empty.', 0.0, 0), $meter, 0, 0, null);
        }

        try {
            $embedding = $this->embedder->embed([$question]);
        } catch (ProviderFailed $e) {
            return $this->log(
                $collection,
                $question,
                Answer::failed('Could not embed the question: '.$e->getMessage(), $meter->totalUsd(), $this->elapsed($startedAt)),
                $meter, 0, 0, null
            );
        }

        $meter->record($this->embedder->model(), $embedding->promptTokens);

        $chunks = $this->retriever->retrieve($collection, $embedding->first());

        if ($chunks === []) {
            return $this->log(
                $collection,
                $question,
                Answer::refused(
                    'Nothing in this collection is close enough to the question to answer it.',
                    $meter->totalUsd(),
                    $this->elapsed($startedAt),
                ),
                $meter, 0, 0, null
            );
        }

        $bestDistance = min(array_map(static fn (RetrievedChunk $c): float => $c->distance, $chunks));

        try {
            $completion = $this->chat->complete($this->buildMessages($question, $chunks));
        } catch (ProviderFailed $e) {
            return $this->log(
                $collection,
                $question,
                Answer::failed('The model call failed: '.$e->getMessage(), $meter->totalUsd(), $this->elapsed($startedAt)),
                $meter, count($chunks), 0, $bestDistance
            );
        }

        $meter->record($this->chat->model(), $completion->promptTokens, $completion->completionTokens);

        $decoded = json_decode($completion->content, true);

        if (! is_array($decoded) || ! isset($decoded['answer'])) {
            return $this->log(
                $collection,
                $question,
                Answer::failed('The model returned a body this service could not parse.', $meter->totalUsd(), $this->elapsed($startedAt)),
                $meter, count($chunks), 0, $bestDistance
            );
        }

        if (($decoded['sufficient'] ?? true) === false) {
            return $this->log(
                $collection,
                $question,
                Answer::refused(
                    is_string($decoded['answer']) && $decoded['answer'] !== ''
                        ? $decoded['answer']
                        : 'The sources do not contain enough to answer this.',
                    $meter->totalUsd(),
                    $this->elapsed($startedAt),
                ),
                $meter, count($chunks), 0, $bestDistance
            );
        }

        $citations = $this->validateCitations($decoded['citations'] ?? [], $chunks);

        if ($citations === []) {
            // The model produced prose but cited nothing that was actually in
            // front of it. That is the shape an invented answer takes, so it
            // is refused rather than returned.
            return $this->log(
                $collection,
                $question,
                Answer::refused(
                    'An answer was produced but could not be traced to any source, so it was discarded.',
                    $meter->totalUsd(),
                    $this->elapsed($startedAt),
                ),
                $meter, count($chunks), 0, $bestDistance
            );
        }

        $text = (string) $decoded['answer'];

        if ($completion->wasTruncated()) {
            $text .= "\n\n[This answer was cut off at the output limit.]";
        }

        return $this->log(
            $collection,
            $question,
            Answer::answered($text, $citations, $meter->totalUsd(), $this->elapsed($startedAt)),
            $meter, count($chunks), count($citations), $bestDistance,
            $completion->promptTokens, $completion->completionTokens, $embedding->promptTokens
        );
    }

    /**
     * @param  list<RetrievedChunk>  $chunks
     * @return list<array{role: string, content: string}>
     */
    private function buildMessages(string $question, array $chunks): array
    {
        $sources = '';

        foreach ($chunks as $index => $chunk) {
            $number = $index + 1;
            $label = $chunk->documentTitle.($chunk->locator !== null ? ', '.$chunk->locator : '');

            $sources .= "[{$number}] {$label}\n{$chunk->content}\n\n";
        }

        $system = <<<'PROMPT'
        You answer questions using only the numbered sources given to you.

        Rules:
        - Use only what the sources say. Do not add facts from your own knowledge, even if you are confident they are correct.
        - Cite the source number for every claim, like [2]. A sentence with no citation should not be in the answer.
        - If the sources disagree, say so and cite both.
        - If the sources do not contain the answer, set "sufficient" to false and explain in one sentence what is missing. Do not guess.
        - Answer in the language the question was asked in.
        - Be brief. Do not restate the question or pad the answer.

        Reply with JSON in exactly this shape:
        {"answer": "...", "citations": [1, 3], "sufficient": true}
        PROMPT;

        $user = <<<PROMPT
        Sources:

        {$sources}
        Question: {$question}
        PROMPT;

        return [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $user],
        ];
    }

    /**
     * @param  mixed  $raw
     * @param  list<RetrievedChunk>  $chunks
     * @return list<array{chunk_id: int, document_id: int, title: string, locator: ?string, similarity: float}>
     */
    private function validateCitations(mixed $raw, array $chunks): array
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

    private function elapsed(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }

    private function log(
        string $collection,
        string $question,
        Answer $answer,
        CostMeter $meter,
        int $considered,
        int $used,
        ?float $bestDistance,
        int $promptTokens = 0,
        int $completionTokens = 0,
        int $embeddingTokens = 0,
    ): Answer {
        QueryLog::create([
            'collection' => $collection,
            'question' => $question,
            'outcome' => $answer->outcome,
            'answer' => $answer->text,
            'citations' => $answer->citations ?: null,
            'chunks_considered' => $considered,
            'chunks_used' => $used,
            'best_distance' => $bestDistance,
            'embedding_tokens' => $embeddingTokens,
            'prompt_tokens' => $promptTokens,
            'completion_tokens' => $completionTokens,
            'cost_usd' => $meter->totalUsd(),
            'pricing_snapshot' => $meter->snapshot() ?: null,
            'latency_ms' => $answer->latencyMs,
            'failure_reason' => $answer->reason,
        ]);

        return $answer;
    }
}
