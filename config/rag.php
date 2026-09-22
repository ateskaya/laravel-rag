<?php

return [

    /*
    |---------------------------------------------------------------------------
    | Embedding model
    |---------------------------------------------------------------------------
    |
    | "dimensions" must match the vector(N) column created by the migration.
    | Changing the model or the dimension count invalidates every stored
    | embedding: old and new vectors are not comparable. Re-embed everything
    | before serving queries again. See README, "Changing the embedding model".
    |
    */

    'embedding' => [
        'model' => env('RAG_EMBEDDING_MODEL', 'text-embedding-3-small'),
        'dimensions' => (int) env('RAG_EMBEDDING_DIMENSIONS', 1536),
        'batch_size' => (int) env('RAG_EMBEDDING_BATCH', 64),
    ],

    /*
    |---------------------------------------------------------------------------
    | Chat model used to turn retrieved chunks into an answer
    |---------------------------------------------------------------------------
    */

    'chat' => [
        'model' => env('RAG_CHAT_MODEL', 'gpt-4o-mini'),
        'temperature' => (float) env('RAG_CHAT_TEMPERATURE', 0.1),
        'max_output_tokens' => (int) env('RAG_CHAT_MAX_TOKENS', 900),
    ],

    /*
    |---------------------------------------------------------------------------
    | Chunking
    |---------------------------------------------------------------------------
    |
    | Sizes are in tokens, approximated at 4 characters per token. The overlap
    | keeps a sentence that straddles a boundary retrievable from both sides.
    |
    */

    'chunking' => [
        'target_tokens' => (int) env('RAG_CHUNK_TOKENS', 800),
        'overlap_tokens' => (int) env('RAG_CHUNK_OVERLAP', 120),
        'min_tokens' => (int) env('RAG_CHUNK_MIN', 40),
    ],

    /*
    |---------------------------------------------------------------------------
    | Retrieval
    |---------------------------------------------------------------------------
    |
    | "candidates" are pulled by vector distance, then the best "keep" of them
    | are passed to the model. "max_distance" is a cosine distance ceiling:
    | anything further away is treated as not relevant, which is what lets the
    | service answer "I don't know" instead of inventing something.
    |
    */

    'retrieval' => [
        'candidates' => (int) env('RAG_RETRIEVAL_CANDIDATES', 40),
        'keep' => (int) env('RAG_RETRIEVAL_KEEP', 6),
        'max_distance' => (float) env('RAG_RETRIEVAL_MAX_DISTANCE', 0.55),
        'max_context_tokens' => (int) env('RAG_RETRIEVAL_MAX_CONTEXT', 6000),
    ],

    /*
    |---------------------------------------------------------------------------
    | Pricing, in USD per 1M tokens
    |---------------------------------------------------------------------------
    |
    | Used only to record what a query cost. These are configuration, not facts:
    | update them when the provider changes its prices. CostMeter records the
    | rates it used alongside the amount, so old rows stay meaningful after a
    | price change.
    |
    */

    'pricing' => [
        'text-embedding-3-small' => ['input' => 0.02, 'output' => 0.0],
        'text-embedding-3-large' => ['input' => 0.13, 'output' => 0.0],
        'gpt-4o-mini' => ['input' => 0.15, 'output' => 0.60],
        'gpt-4o' => ['input' => 2.50, 'output' => 10.00],
    ],

    /*
    |---------------------------------------------------------------------------
    | OpenAI transport
    |---------------------------------------------------------------------------
    */

    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),
        'base_uri' => env('OPENAI_BASE_URI', 'https://api.openai.com/v1/'),
        'timeout' => (int) env('OPENAI_TIMEOUT', 60),
        'connect_timeout' => (int) env('OPENAI_CONNECT_TIMEOUT', 10),
    ],

    /*
    |---------------------------------------------------------------------------
    | Queue
    |---------------------------------------------------------------------------
    |
    | Ingestion is asynchronous. Put it on its own queue so a large upload does
    | not starve the queues your application needs for user-facing work.
    |
    */

    'queue' => [
        'connection' => env('RAG_QUEUE_CONNECTION'),
        'name' => env('RAG_QUEUE', 'rag'),
    ],

];
