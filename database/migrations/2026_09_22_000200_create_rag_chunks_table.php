<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $dimensions = (int) config('rag.embedding.dimensions', 1536);

        Schema::create('rag_chunks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained('rag_documents')->cascadeOnDelete();
            $table->string('collection')->index();

            $table->unsignedInteger('position');
            $table->text('content');
            $table->unsignedInteger('token_estimate');

            // Where this chunk came from inside the document, so a citation can
            // point at a page or a heading rather than at an opaque row id.
            $table->string('locator')->nullable();

            $table->timestamps();

            $table->unique(['document_id', 'position']);
        });

        // The vector column is added outside the Blueprint: Laravel's schema
        // builder has no native vector type.
        DB::statement("ALTER TABLE rag_chunks ADD COLUMN embedding vector({$dimensions})");

        // HNSW over cosine distance. Built after the column exists and, in a
        // real deployment, ideally after the first bulk load: building the
        // index on an empty table and then inserting is slower than the
        // reverse, but it keeps this migration idempotent and simple.
        DB::statement('CREATE INDEX rag_chunks_embedding_idx ON rag_chunks USING hnsw (embedding vector_cosine_ops)');

        // Retrieval always filters by collection before ranking, so this
        // composite index carries the pre-filter.
        DB::statement('CREATE INDEX rag_chunks_collection_pending_idx ON rag_chunks (collection) WHERE embedding IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('rag_chunks');
    }
};
