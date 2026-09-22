<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rag_queries', function (Blueprint $table) {
            $table->id();
            $table->string('collection')->index();
            $table->text('question');

            // answered | refused | failed
            $table->string('outcome', 20)->index();

            $table->text('answer')->nullable();
            $table->json('citations')->nullable();

            $table->unsignedInteger('chunks_considered')->default(0);
            $table->unsignedInteger('chunks_used')->default(0);
            $table->float('best_distance')->nullable();

            $table->unsignedInteger('embedding_tokens')->default(0);
            $table->unsignedInteger('prompt_tokens')->default(0);
            $table->unsignedInteger('completion_tokens')->default(0);

            // Stored to 6 decimals: a single query often costs a fraction of a
            // cent, and rounding to 2 would record every query as zero.
            $table->decimal('cost_usd', 12, 6)->default(0);

            // The price list in force when this row was written, so the number
            // above stays explainable after a provider price change.
            $table->json('pricing_snapshot')->nullable();

            $table->unsignedInteger('latency_ms')->nullable();
            $table->text('failure_reason')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rag_queries');
    }
};
