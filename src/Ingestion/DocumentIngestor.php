<?php

namespace IbrahimEnsar\Rag\Ingestion;

use Illuminate\Support\Facades\DB;
use IbrahimEnsar\Rag\Chunking\TextChunker;
use IbrahimEnsar\Rag\Jobs\EmbedChunkBatch;
use IbrahimEnsar\Rag\Models\Chunk;
use IbrahimEnsar\Rag\Models\Document;

/**
 * Takes extracted text and gets it into the database as chunks, then hands the
 * embedding work to the queue.
 *
 * Nothing here calls the embedding API. That is the point: ingesting a
 * 200-page PDF is a slow, rate-limited, failure-prone operation, and doing it
 * inside the request that uploaded the file means the user watches a spinner
 * for two minutes and then sees a gateway timeout. Here the request returns as
 * soon as the text is stored, and the caller polls the document's status.
 */
class DocumentIngestor
{
    public function __construct(
        private readonly TextChunker $chunker,
        private readonly int $batchSize = 64,
    ) {
    }

    /**
     * @param  array<int, string>  $pages
     */
    public function ingest(
        string $collection,
        string $title,
        string $text,
        array $pages = [],
        array $metadata = [],
        ?string $sourcePath = null,
        ?string $mimeType = null,
    ): Document {
        $text = trim($text);

        if ($text === '') {
            throw new \InvalidArgumentException('Refusing to ingest a document with no extractable text.');
        }

        $hash = hash('sha256', $text);

        // Hashing the extracted text rather than the file means the same
        // content re-uploaded as a different export, or under a different
        // filename, is recognised and not paid for twice.
        $existing = Document::query()
            ->where('collection', $collection)
            ->where('content_hash', $hash)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $chunks = $this->chunker->chunk($text, $pages);

        if ($chunks === []) {
            throw new \InvalidArgumentException('Chunking produced nothing; the document appears to be empty.');
        }

        $document = DB::transaction(function () use (
            $collection, $title, $hash, $chunks, $metadata, $sourcePath, $mimeType
        ): Document {
            $document = Document::create([
                'collection' => $collection,
                'title' => $title,
                'source_path' => $sourcePath,
                'mime_type' => $mimeType,
                'content_hash' => $hash,
                'chunk_count' => count($chunks),
                'chunks_embedded' => 0,
                'status' => Document::STATUS_PROCESSING,
                'metadata' => $metadata ?: null,
            ]);

            $now = now();

            // Insert in pages rather than one statement per chunk: a
            // 200-page PDF is a few thousand rows, and a few thousand
            // round-trips is the difference between 200ms and 40 seconds.
            foreach (array_chunk($chunks, 500) as $slice) {
                Chunk::insert(array_map(static fn ($chunk): array => [
                    'document_id' => $document->id,
                    'collection' => $document->collection,
                    'position' => $chunk->position,
                    'content' => $chunk->content,
                    'token_estimate' => $chunk->tokenEstimate,
                    'locator' => $chunk->locator,
                    'created_at' => $now,
                    'updated_at' => $now,
                ], $slice));
            }

            return $document;
        });

        $this->dispatchEmbedding($document);

        return $document;
    }

    private function dispatchEmbedding(Document $document): void
    {
        $ids = Chunk::query()
            ->where('document_id', $document->id)
            ->orderBy('position')
            ->pluck('id')
            ->all();

        foreach (array_chunk($ids, $this->batchSize) as $batch) {
            EmbedChunkBatch::dispatch($document->id, $batch);
        }
    }
}
