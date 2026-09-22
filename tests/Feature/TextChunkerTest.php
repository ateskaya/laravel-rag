<?php

namespace IbrahimEnsar\Rag\Tests\Feature;

use IbrahimEnsar\Rag\Chunking\TextChunker;
use PHPUnit\Framework\TestCase;

class TextChunkerTest extends TestCase
{
    public function test_it_keeps_short_documents_in_one_chunk(): void
    {
        $chunker = new TextChunker(targetTokens: 800, overlapTokens: 120, minTokens: 40);

        $chunks = $chunker->chunk("First paragraph about payroll.\n\nSecond paragraph about leave.");

        $this->assertCount(1, $chunks);
        $this->assertStringContainsString('payroll', $chunks[0]->content);
        $this->assertStringContainsString('leave', $chunks[0]->content);
    }

    public function test_it_splits_on_paragraph_boundaries_rather_than_mid_sentence(): void
    {
        $chunker = new TextChunker(targetTokens: 30, overlapTokens: 0, minTokens: 1);

        $paragraphs = [];
        for ($i = 1; $i <= 6; $i++) {
            $paragraphs[] = "Paragraph number {$i} describing one complete and self contained idea.";
        }

        $chunks = $chunker->chunk(implode("\n\n", $paragraphs));

        $this->assertGreaterThan(1, count($chunks));

        foreach ($chunks as $chunk) {
            // Every chunk should end on punctuation, which is the observable
            // consequence of never cutting inside a sentence.
            $this->assertMatchesRegularExpression('/[.!?]$/', trim($chunk->content));
        }
    }

    public function test_it_hard_splits_text_that_has_no_sentence_boundaries(): void
    {
        $chunker = new TextChunker(targetTokens: 20, overlapTokens: 0, minTokens: 1);

        $chunks = $chunker->chunk(str_repeat('abcdefgh', 200));

        $this->assertGreaterThan(1, count($chunks));

        foreach ($chunks as $chunk) {
            $this->assertLessThanOrEqual(25, $chunk->tokenEstimate);
        }
    }

    public function test_overlap_carries_context_into_the_next_chunk(): void
    {
        $chunker = new TextChunker(targetTokens: 40, overlapTokens: 20, minTokens: 1);

        $text = "The notice period is thirty days for all staff.\n\n"
            ."This applies from the first day of employment.\n\n"
            ."Managers may agree a shorter period in writing.\n\n"
            ."Any such agreement must be filed with HR before the leaving date.";

        $chunks = $chunker->chunk($text);

        $this->assertGreaterThan(1, count($chunks));

        // The second chunk should begin with material the first one also held.
        $this->assertNotSame('', trim($chunks[1]->content));
        $this->assertStringContainsString(
            mb_substr(trim($chunks[1]->content), 0, 12),
            $chunks[0]->content.$chunks[1]->content
        );
    }

    public function test_it_records_the_page_a_chunk_started_on(): void
    {
        $chunker = new TextChunker(targetTokens: 15, overlapTokens: 0, minTokens: 1);

        $chunks = $chunker->chunk('', [
            1 => 'Content that belongs to the first page of the handbook.',
            2 => 'Content that belongs to the second page of the handbook.',
        ]);

        $locators = array_map(static fn ($c) => $c->locator, $chunks);

        $this->assertContains('p. 1', $locators);
        $this->assertContains('p. 2', $locators);
    }

    public function test_short_pages_are_packed_together_and_keep_the_starting_page(): void
    {
        // Pages are a source of locators, not a hard boundary. Two short pages
        // that fit inside one chunk stay together -- splitting them would
        // produce two chunks too small to retrieve well -- and the chunk is
        // labelled with the page it began on.
        $chunker = new TextChunker(targetTokens: 800, overlapTokens: 0, minTokens: 1);

        $chunks = $chunker->chunk('', [
            4 => 'A short page.',
            5 => 'Another short page.',
        ]);

        $this->assertCount(1, $chunks);
        $this->assertSame('p. 4', $chunks[0]->locator);
        $this->assertStringContainsString('Another short page.', $chunks[0]->content);
    }

    public function test_a_tiny_trailing_fragment_is_merged_backwards(): void
    {
        $chunker = new TextChunker(targetTokens: 30, overlapTokens: 0, minTokens: 100);

        $chunks = $chunker->chunk(
            "A reasonably long opening paragraph that will fill most of the first chunk on its own.\n\nOk."
        );

        // "Ok." is below the floor, so it must not become a chunk of its own.
        foreach ($chunks as $chunk) {
            $this->assertNotSame('Ok.', trim($chunk->content));
        }

        $this->assertStringContainsString('Ok.', end($chunks)->content);
    }
}
