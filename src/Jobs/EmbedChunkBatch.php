<?php

namespace IbrahimEnsar\Rag\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use IbrahimEnsar\Rag\Contracts\EmbeddingProvider;
use IbrahimEnsar\Rag\Exceptions\ProviderFailed;
use IbrahimEnsar\Rag\Exceptions\ProviderRateLimited;
use IbrahimEnsar\Rag\Models\Chunk;
use IbrahimEnsar\Rag\Models\Document;
use IbrahimEnsar\Rag\Support\Vector;
use Throwable;

/**
 * Embeds one batch of chunks.
 *
 * The retry behaviour is the part that matters. Embedding a large document
 * means dozens of calls against a per-minute token limit, so hitting 429 is
 * normal operation, not an exception. Two rules follow:
 *
 *  - A rate limit is retried with exponential backoff, and `retryUntil` gives
 *    the whole job a wall-clock deadline rather than a fixed attempt count, so
 *    a long throttle does not exhaust the attempts in ninety seconds.
 *  - A 400 is not retried at all. Releasing it back would burn the remaining
 *    attempts on a request guaranteed to fail the same way.
 *
 * The job is also idempotent: it only selects chunks whose embedding is still
 * null, so a retry after a partial success re-embeds only what is missing.
 */
class EmbedChunkBatch implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 12;

    /** Seconds to wait before successive retries. The last value repeats. */
    public array $backoff = [5, 15, 30, 60, 120, 300];

    public function __construct(
        public readonly int $documentId,
        /** @var list<int> */
        public readonly array $chunkIds,
    ) {
        $this->onQueue(config('rag.queue.name', 'rag'));

        if ($connection = config('rag.queue.connection')) {
            $this->onConnection($connection);
        }
    }

    /**
     * Give up after an hour of wall-clock time regardless of attempts. A
     * document stuck behind a sustained outage should fail visibly rather than
     * sit in the queue all day.
     */
    public function retryUntil(): \DateTimeInterface
    {
        return now()->addHour();
    }

    public function handle(EmbeddingProvider $embedder): void
    {
        $chunks = Chunk::query()
            ->whereIn('id', $this->chunkIds)
            ->whereNull('embedding')
            ->orderBy('position')
            ->get(['id', 'content']);

        if ($chunks->isEmpty()) {
            // Already embedded by an earlier attempt. Still refresh the
            // document's counters, because the attempt that did the work may
            // have died before updating them.
            $this->refreshDocumentStatus();

            return;
        }

        try {
            $result = $embedder->embed($chunks->pluck('content')->all());
        } catch (ProviderRateLimited $e) {
            $this->release($e->retryAfterSeconds ?? $this->backoffForAttempt());

            return;
        } catch (ProviderFailed $e) {
            // Unrecoverable for this payload. Fail now and let the document
            // carry the reason, rather than retrying eleven more times.
            $this->markDocumentFailed($e->getMessage());
            $this->fail($e);

            return;
        }

        DB::transaction(function () use ($chunks, $result): void {
            foreach ($chunks as $index => $chunk) {
                $vector = $result->vectors[$index] ?? null;

                if ($vector === null) {
                    continue;
                }

                DB::statement(
                    'UPDATE rag_chunks SET embedding = ?::vector, updated_at = ? WHERE id = ?',
                    [Vector::toLiteral($vector), now(), $chunk->id]
                );
            }
        });

        $this->refreshDocumentStatus();
    }

    public function failed(Throwable $e): void
    {
        $this->markDocumentFailed($e->getMessage());

        Log::error('RAG embedding batch failed permanently.', [
            'document_id' => $this->documentId,
            'chunks' => count($this->chunkIds),
            'reason' => $e->getMessage(),
        ]);
    }

    private function backoffForAttempt(): int
    {
        $attempt = max(1, $this->attempts());

        return $this->backoff[min($attempt - 1, count($this->backoff) - 1)];
    }

    private function refreshDocumentStatus(): void
    {
        $document = Document::find($this->documentId);

        if ($document === null) {
            return;
        }

        $embedded = Chunk::query()
            ->where('document_id', $document->id)
            ->whereNotNull('embedding')
            ->count();

        $document->forceFill([
            'chunks_embedded' => $embedded,
            'status' => $embedded >= $document->chunk_count
                ? Document::STATUS_READY
                : Document::STATUS_PROCESSING,
        ])->save();
    }

    private function markDocumentFailed(string $reason): void
    {
        Document::whereKey($this->documentId)->update([
            'status' => Document::STATUS_FAILED,
            'failure_reason' => mb_substr($reason, 0, 1000),
            'updated_at' => now(),
        ]);
    }
}
