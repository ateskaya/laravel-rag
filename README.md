# laravel-rag

Retrieval-augmented answering for Laravel applications, backed by Postgres and pgvector.

Point it at your documents, ask a question, get an answer with citations — or get told, honestly, that the documents don't contain the answer.

This is not a demo. It is written the way I would write it for an application that already has paying users: ingestion runs on the queue, rate limits are expected rather than exceptional, every query records what it cost, and the service refuses to answer rather than inventing one.

---

## Why this exists

Most RAG examples work beautifully on the document you tested them with, and then fail in production in four predictable ways:

1. **They chunk by character count**, cutting sentences in half. The embedding of half a clause sits nowhere near the embedding of the question it should have answered, so retrieval quietly degrades.
2. **They embed inside the web request.** A 200-page PDF means dozens of rate-limited API calls, the request times out, and the user is left with a half-ingested document and no way to tell.
3. **They always answer.** Vector search returns its k nearest neighbours whether or not any of them are relevant, so asking an HR handbook about football scores returns six confident paragraphs about annual leave.
4. **Nobody knows what it costs** until the invoice arrives.

Each of those has a fix, and the fixes are what this package is.

---

## What it does

- **Structure-aware chunking** — splits on paragraphs, packs short ones together, falls back to sentence boundaries only when a paragraph is oversized, and hard-cuts only for text with no sentence structure at all (tables, minified payloads). Overlap carries context across boundaries.
- **Queued ingestion** — the upload request returns as soon as text is stored. Embedding happens on its own queue, in batches, idempotently.
- **Rate limits treated as normal** — 429 and 5xx are retried with exponential backoff under a wall-clock deadline. 4xx is not retried at all, because it never will succeed.
- **Grounded answers with verified citations** — the model is told to cite; every citation it returns is then checked against what was actually sent. Invented citations are stripped, and an answer with no surviving citations is downgraded to a refusal.
- **Honest refusal** — a cosine-distance ceiling means "nothing here is close enough" is a reachable outcome, not a theoretical one.
- **Cost and latency per query** — recorded from the provider's own usage figures, stored alongside the price list that produced them.
- **Multi-tenant by default** — every chunk carries a `collection`, and the filter is applied inside the SQL, not after it.

---

## Requirements

- PHP 8.2+
- Laravel 11 or 12
- PostgreSQL 13+ with the [`pgvector`](https://github.com/pgvector/pgvector) extension
- An OpenAI API key (or your own implementation of the two provider interfaces)
- A queue worker — `sync` will work but defeats the point

---

## Install

```bash
composer require ateskaya/laravel-rag
php artisan vendor:publish --tag=rag-config
php artisan migrate
```

```dotenv
OPENAI_API_KEY=sk-...
RAG_EMBEDDING_MODEL=text-embedding-3-small
RAG_EMBEDDING_DIMENSIONS=1536
RAG_CHAT_MODEL=gpt-4o-mini
RAG_QUEUE=rag
```

Run a worker for the ingestion queue:

```bash
php artisan queue:work --queue=rag
```

---

## Usage

### Ingest

```php
use IbrahimEnsar\Rag\Ingestion\DocumentIngestor;

$document = app(DocumentIngestor::class)->ingest(
    collection: 'acme-handbook',
    title:      'Employee Handbook 2026',
    text:       $extractedText,
    pages:      $pageKeyedText,   // optional: gives citations a page number
    metadata:   ['department' => 'hr'],
);

$document->status;      // processing
$document->progress();  // 0.0 .. 1.0
```

Ingestion returns immediately. Poll `status` until it reads `ready`, or `failed` with `failure_reason` set.

Re-ingesting the same content is free: documents are deduplicated on a SHA-256 of the *extracted text*, so the same handbook re-uploaded as a different export, or under a different filename, is recognised and not paid for twice.

### Ask

```php
use IbrahimEnsar\Rag\Answering\AnswerService;

$answer = app(AnswerService::class)->ask('acme-handbook', 'How much notice do I have to give?');

if ($answer->wasAnswered()) {
    echo $answer->text;
    // "Thirty days for all staff [1]. Managers may agree a shorter period in writing [2]."

    foreach ($answer->citations as $citation) {
        echo $citation['title'].' — '.$citation['locator'];   // Employee Handbook 2026 — p. 12
    }
} else {
    echo $answer->reason;
    // "Nothing in this collection is close enough to the question to answer it."
}

$answer->costUsd;    // 0.000412
$answer->latencyMs;  // 1840
```

Every call — answered, refused or failed — writes a row to `rag_queries`. That table is the operational record: what was asked, what came back, how close the nearest chunk was, what it cost.

---

## Design decisions

### Postgres and pgvector, not a dedicated vector database

A separate vector store means a second system to run, back up and keep consistent with the source rows. At the scale most applications actually operate at — tens of thousands of chunks, not billions — pgvector with an HNSW index is fast enough, and the chunks live in the same transaction as the documents they came from. Deleting a document deletes its vectors, with no reconciliation job and no window where the two disagree.

The trade-off is real: past roughly ten million vectors, a purpose-built store wins on both speed and memory. If you are there, replace `VectorRetriever` — it is the only class that knows how vectors are stored.

### Embedding happens on the queue, never in the request

A 200-page document is thousands of chunks and dozens of API calls against a per-minute token limit. Doing that inline means a two-minute request that ends in a gateway timeout, with the document half-ingested and no record of where it stopped.

`EmbedChunkBatch` is idempotent — it selects only chunks whose embedding is still null — so a retry after a partial success re-embeds only what is missing. The document's `chunks_embedded` counter is recomputed from the table rather than incremented, so a job that dies after writing vectors but before updating counters self-corrects on the next attempt.

### Rate limits and hard failures are different things

They are separate exception types (`ProviderRateLimited`, `ProviderFailed`) because they call for opposite responses:

| Condition | Response |
| --- | --- |
| 429, 5xx, connection failure | Release with backoff, honouring `Retry-After`. Normal operation. |
| 400, 401, 403, 404 | Fail immediately, mark the document, stop. Retrying is guaranteed to waste attempts. |

`retryUntil()` gives the job a one-hour wall-clock deadline rather than a fixed attempt count, so a sustained throttle doesn't burn twelve attempts in ninety seconds and give up while the API is merely busy.

### Refusal is a first-class outcome

Three layers, because each one alone leaks:

1. **The distance ceiling** (`retrieval.max_distance`) drops chunks that aren't close enough, so an unrelated question reaches the model with no context at all.
2. **The prompt** instructs the model to set `sufficient: false` rather than guess.
3. **Citation validation** checks every citation the model returns against the chunks that were actually sent. A model that cites `[9]` when six sources were provided gets that citation stripped; an answer left with no valid citations is refused rather than returned.

Layer 3 is the one that does the real work. Instructions are a request. Validating the output is a guarantee.

### Cost is measured, not estimated

`TokenCounter` is a crude 4-characters-per-token approximation, and that is deliberate: it sizes chunks and caps a context budget, and being 10% off costs nothing when the budget already sits below the hard limit. An exact tokenizer would mean either a PHP BPE port that drifts out of date or a service call per chunk.

Money is different. `CostMeter` uses the provider's own reported usage, never the estimate, and stores the price list in force at the time next to the amount — so last quarter's rows stay explainable after a price change.

### Neighbouring chunks come along

When chunk 7 is the best match, chunks 6 and 8 usually carry the sentence that finishes the thought. Pulling them in costs one extra SQL query rather than one extra embedding, and a neighbour inherits its anchor's relevance so it isn't dropped for having no distance of its own.

---

## Failure modes, and what happens

Things that will go wrong, in roughly descending order of likelihood:

| What happens | What the package does | What you should do |
| --- | --- | --- |
| Embedding API rate-limits mid-ingest | Job released with backoff; document stays `processing` | Nothing. It resolves itself. |
| API key invalid or revoked | Job fails immediately, document marked `failed` with the reason | Fix the key, re-dispatch |
| PDF has no extractable text (a scan) | `ingest()` throws before writing anything | OCR it first; this package does not |
| Question is unrelated to the collection | Outcome `refused`, no model call billed | Nothing. Correct behaviour. |
| Model invents a citation | Citation stripped; if none survive, outcome `refused` | Check `rag_queries` for a pattern |
| Model returns prose instead of JSON | Outcome `failed`, logged, no partial answer returned | Rare with JSON mode on; check the model name |
| Answer hits the output ceiling | Returned with an explicit truncation note appended | Raise `RAG_CHAT_MAX_TOKENS` |
| Chunk larger than the context budget | Skipped during budget fill | Lower `RAG_CHUNK_TOKENS` |
| Embedding model changed underneath you | Nothing — and this is the dangerous one | See below |

### Changing the embedding model

Vectors from two different models are not comparable. Changing `RAG_EMBEDDING_MODEL` or `RAG_EMBEDDING_DIMENSIONS` on a populated database does not error — it silently returns nonsense, because distances are being computed between vectors that live in different spaces.

The migration path is: add a second vector column, backfill it, switch the retriever, drop the old column. There is no shortcut, and the package does not pretend otherwise.

---

## What this does not do

Stated plainly, because a portfolio piece that implies more than it delivers is worse than one that is honest:

- **No OCR.** Scanned PDFs with no text layer must be processed before ingestion.
- **No reranker.** A cross-encoder pass over the candidates would improve precision meaningfully. It is the first thing I would add.
- **No hybrid search.** Pure vector search handles paraphrase well and exact identifiers — an order number, an error code — badly. BM25 alongside the vector query, fused, is the standard fix.
- **No conversation memory.** Each question is independent. Follow-ups that say "and what about him?" will not resolve the pronoun.
- **No access control.** `collection` separates tenants; it does not authorise users. Authorisation is your application's job.

---

## Tests

```bash
composer install
vendor/bin/phpunit
```

The chunker tests are the ones worth reading: they assert the behaviours that actually determine retrieval quality — that chunks end on sentence boundaries, that overlap carries context forward, that a fragment too small to embed usefully is merged backwards instead of stored alone.

---

## Licence

MIT.
