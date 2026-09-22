<?php

namespace IbrahimEnsar\Rag;

use Illuminate\Support\ServiceProvider;
use IbrahimEnsar\Rag\Answering\AnswerService;
use IbrahimEnsar\Rag\Chunking\TextChunker;
use IbrahimEnsar\Rag\Contracts\ChatProvider;
use IbrahimEnsar\Rag\Contracts\EmbeddingProvider;
use IbrahimEnsar\Rag\Ingestion\DocumentIngestor;
use IbrahimEnsar\Rag\Providers\OpenAiChatProvider;
use IbrahimEnsar\Rag\Providers\OpenAiClient;
use IbrahimEnsar\Rag\Providers\OpenAiEmbeddingProvider;
use IbrahimEnsar\Rag\Retrieval\VectorRetriever;

class RagServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/rag.php', 'rag');

        $this->app->singleton(OpenAiClient::class, fn ($app) => OpenAiClient::fromConfig($app['config']['rag.openai']));

        // Both providers are bound to interfaces so an application can swap in
        // a different vendor, or a fake in tests, without touching anything
        // downstream.
        $this->app->bind(EmbeddingProvider::class, fn ($app) => new OpenAiEmbeddingProvider(
            $app->make(OpenAiClient::class),
            $app['config']['rag.embedding.model'],
            (int) $app['config']['rag.embedding.dimensions'],
        ));

        $this->app->bind(ChatProvider::class, fn ($app) => new OpenAiChatProvider(
            $app->make(OpenAiClient::class),
            $app['config']['rag.chat.model'],
            (float) $app['config']['rag.chat.temperature'],
            (int) $app['config']['rag.chat.max_output_tokens'],
        ));

        $this->app->bind(TextChunker::class, fn ($app) => new TextChunker(
            (int) $app['config']['rag.chunking.target_tokens'],
            (int) $app['config']['rag.chunking.overlap_tokens'],
            (int) $app['config']['rag.chunking.min_tokens'],
        ));

        $this->app->bind(VectorRetriever::class, fn ($app) => new VectorRetriever(
            (int) $app['config']['rag.retrieval.candidates'],
            (int) $app['config']['rag.retrieval.keep'],
            (float) $app['config']['rag.retrieval.max_distance'],
            (int) $app['config']['rag.retrieval.max_context_tokens'],
        ));

        $this->app->bind(DocumentIngestor::class, fn ($app) => new DocumentIngestor(
            $app->make(TextChunker::class),
            (int) $app['config']['rag.embedding.batch_size'],
        ));

        $this->app->bind(AnswerService::class, fn ($app) => new AnswerService(
            $app->make(EmbeddingProvider::class),
            $app->make(ChatProvider::class),
            $app->make(VectorRetriever::class),
            $app['config']['rag.pricing'],
        ));
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/rag.php' => config_path('rag.php'),
            ], 'rag-config');
        }
    }
}
