<?php

namespace IbrahimEnsar\Rag\Tests\Feature;

use IbrahimEnsar\Rag\Answering\GroundingCheck;
use IbrahimEnsar\Rag\Retrieval\RetrievedChunk;
use PHPUnit\Framework\TestCase;

class GroundingCheckTest extends TestCase
{
    /** Three sources, numbered [1]..[3] in the prompt, the way AnswerService sends them. */
    private function sources(): array
    {
        return [
            new RetrievedChunk(101, 10, 'Leave Policy', 'Employees accrue 25 days of annual leave.', 'p. 4', 0.18, 12),
            new RetrievedChunk(102, 10, 'Leave Policy', 'Unused leave may be carried over up to 5 days.', 'p. 5', 0.22, 12),
            new RetrievedChunk(203, 20, 'Expenses', 'Receipts are required for claims over 25 EUR.', null, 0.41, 10),
        ];
    }

    public function test_an_answer_citing_real_sources_is_returned_with_those_sources(): void
    {
        $check = GroundingCheck::evaluate(
            '{"answer": "You get 25 days [1], and can carry over 5 [2].", "citations": [1, 2], "sufficient": true}',
            $this->sources(),
        );

        $this->assertSame(GroundingCheck::ANSWERED, $check->outcome);
        $this->assertSame([101, 102], array_column($check->citations, 'chunk_id'));
        $this->assertSame('p. 4', $check->citations[0]['locator']);
        $this->assertSame(0.82, $check->citations[0]['similarity']);
    }

    public function test_an_invented_citation_is_stripped_and_the_real_one_kept(): void
    {
        // Three sources were sent. [9] cannot exist.
        $check = GroundingCheck::evaluate(
            '{"answer": "25 days [1], per the 2024 memo [9].", "citations": [1, 9], "sufficient": true}',
            $this->sources(),
        );

        $this->assertSame(GroundingCheck::ANSWERED, $check->outcome);
        $this->assertCount(1, $check->citations);
        $this->assertSame(101, $check->citations[0]['chunk_id']);
    }

    public function test_prose_whose_every_citation_is_invented_is_not_returned(): void
    {
        $check = GroundingCheck::evaluate(
            '{"answer": "Staff get 30 days [7][8].", "citations": [7, 8], "sufficient": true}',
            $this->sources(),
        );

        $this->assertSame(GroundingCheck::UNGROUNDED, $check->outcome);
        $this->assertSame(null, $check->text);
        $this->assertSame([], $check->citations);
    }

    public function test_prose_with_no_citations_at_all_is_not_returned(): void
    {
        $check = GroundingCheck::evaluate('{"answer": "Probably 25 days.", "sufficient": true}', $this->sources());

        $this->assertSame(GroundingCheck::UNGROUNDED, $check->outcome);
    }

    public function test_the_model_saying_the_sources_are_insufficient_is_a_refusal_with_its_reason(): void
    {
        $check = GroundingCheck::evaluate(
            '{"answer": "The sources cover leave and expenses, not parental leave.", "citations": [], "sufficient": false}',
            $this->sources(),
        );

        $this->assertSame(GroundingCheck::INSUFFICIENT, $check->outcome);
        $this->assertSame('The sources cover leave and expenses, not parental leave.', $check->text);
    }

    public function test_an_insufficient_reply_with_a_blank_reason_leaves_the_text_to_the_caller(): void
    {
        $check = GroundingCheck::evaluate('{"answer": "   ", "sufficient": false}', $this->sources());

        $this->assertSame(GroundingCheck::INSUFFICIENT, $check->outcome);
        $this->assertSame(null, $check->text);
    }

    public function test_citations_to_insufficient_answers_are_ignored(): void
    {
        // A model that says "not enough" must not smuggle an answer through its citations.
        $check = GroundingCheck::evaluate(
            '{"answer": "Not covered.", "citations": [1], "sufficient": false}',
            $this->sources(),
        );

        $this->assertSame(GroundingCheck::INSUFFICIENT, $check->outcome);
        $this->assertSame([], $check->citations);
    }

    public function test_a_body_that_is_not_the_agreed_shape_is_unparseable(): void
    {
        foreach (['Sure! You get 25 days.', '{"text": "25 days"}', '[1, 2]', ''] as $body) {
            $this->assertSame(GroundingCheck::UNPARSEABLE, GroundingCheck::evaluate($body, $this->sources())->outcome);
        }
    }

    public function test_the_same_source_cited_twice_is_reported_once(): void
    {
        $check = GroundingCheck::evaluate(
            '{"answer": "25 days [1]. Again, 25 days [1].", "citations": [1, 1, "1"], "sufficient": true}',
            $this->sources(),
        );

        $this->assertCount(1, $check->citations);
    }

    public function test_only_positive_whole_numbers_count_as_citations(): void
    {
        // "3" as a string is accepted (models do this); 0, negatives, fractions and words are not.
        $check = GroundingCheck::evaluate(
            '{"answer": "Receipts over 25 EUR [3].", "citations": [0, -1, 1.5, "abc", "3"], "sufficient": true}',
            $this->sources(),
        );

        $this->assertSame(GroundingCheck::ANSWERED, $check->outcome);
        $this->assertSame([203], array_column($check->citations, 'chunk_id'));
    }

    public function test_a_missing_sufficient_flag_is_treated_as_an_attempted_answer_and_still_checked(): void
    {
        $check = GroundingCheck::evaluate('{"answer": "25 days [4].", "citations": [4]}', $this->sources());

        $this->assertSame(GroundingCheck::UNGROUNDED, $check->outcome);
    }
}
