<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS vector');

        Schema::create('rag_documents', function (Blueprint $table) {
            $table->id();

            // Owning scope. Every retrieval is filtered by this, so one
            // deployment can serve many tenants without leaking between them.
            $table->string('collection')->index();

            $table->string('title');
            $table->string('source_path')->nullable();
            $table->string('mime_type')->nullable();

            // Hash of the extracted text, not of the uploaded file: two PDFs
            // that render the same text should not be embedded twice.
            $table->string('content_hash', 64);

            $table->unsignedInteger('chunk_count')->default(0);
            $table->unsignedInteger('chunks_embedded')->default(0);

            // pending -> processing -> ready | failed
            $table->string('status', 20)->default('pending')->index();
            $table->text('failure_reason')->nullable();

            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['collection', 'content_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rag_documents');
    }
};
