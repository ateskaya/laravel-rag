<?php

namespace IbrahimEnsar\Rag\Retrieval;

use Illuminate\Support\Facades\DB;
use IbrahimEnsar\Rag\Support\TokenCounter;
use IbrahimEnsar\Rag\Support\Vector;

/**
 * Finds the chunks worth showing the model.
 *
 * Three things happen beyond "order by distance":
 *
 *  - The collection filter is applied inside the query, not after. Retrieving
 *    globally and filtering in PHP would leak one tenant's documents into
 *    another tenant's candidate set, and would waste the index.
 *
 *  - A distance ceiling is enforced. Vector search always returns its k
 *    nearest neighbours, even when the nearest neighbour is nothing to do with
 *    the question. Without a ceiling, asking an HR handbook about football
 *    scores returns six confident paragraphs about annual leave, and the model
 *    dutifully builds an answer out of them. The ceiling is what makes "I
 *    don't know" reachable.
 *
 *  - Neighbouring chunks are pulled in. When chunk 7 is the best match,
 *    chunks 6 and 8 usually carry the sentence that finishes the thought, and
 *    they cost one extra query rather than one extra embedding.
 */
class VectorRetriever
{
    public function __construct(
        private readonly int $candidates = 40,
        private readonly int $keep = 6,
        private readonly float $maxDistance = 0.55,
        private readonly int $maxContextTokens = 6000,
    ) {
    }

    /**
     * @param  list<float>  $queryVector
     * @return list<RetrievedChunk>
     */
    public function retrieve(string $collection, array $queryVector): array
    {
        $literal = Vector::toLiteral($queryVector);

        $rows = DB::select(
            <<<'SQL'
            SELECT
                c.id,
                c.document_id,
                c.position,
                c.content,
                c.locator,
                c.token_estimate,
                d.title AS document_title,
                c.embedding <=> ?::vector AS distance
            FROM rag_chunks c
            INNER JOIN rag_documents d ON d.id = c.document_id
            WHERE c.collection = ?
              AND c.embedding IS NOT NULL
              AND d.status = 'ready'
            ORDER BY c.embedding <=> ?::vector
            LIMIT ?
            SQL,
            [$literal, $collection, $literal, $this->candidates]
        );

        $relevant = array_values(array_filter(
            $rows,
            fn (object $row): bool => (float) $row->distance <= $this->maxDistance
        ));

        if ($relevant === []) {
            return [];
        }

        $selected = array_slice($relevant, 0, $this->keep);

        return $this->withNeighbours($collection, $selected);
    }

    /**
     * @param  list<object>  $rows
     * @return list<RetrievedChunk>
     */
    private function withNeighbours(string $collection, array $rows): array
    {
        $wanted = [];

        foreach ($rows as $row) {
            $documentId = (int) $row->document_id;
            $position = (int) $row->position;

            foreach ([$position - 1, $position, $position + 1] as $neighbour) {
                if ($neighbour < 0) {
                    continue;
                }

                $wanted[$documentId][$neighbour] = true;
            }
        }

        $distanceByChunk = [];
        foreach ($rows as $row) {
            $distanceByChunk[(int) $row->id] = (float) $row->distance;
        }

        $query = DB::table('rag_chunks as c')
            ->join('rag_documents as d', 'd.id', '=', 'c.document_id')
            ->where('c.collection', $collection)
            ->select('c.id', 'c.document_id', 'c.position', 'c.content', 'c.locator', 'c.token_estimate', 'd.title as document_title');

        $query->where(function ($outer) use ($wanted): void {
            foreach ($wanted as $documentId => $positions) {
                $outer->orWhere(function ($inner) use ($documentId, $positions): void {
                    $inner->where('c.document_id', $documentId)
                        ->whereIn('c.position', array_keys($positions));
                });
            }
        });

        $expanded = $query->orderBy('c.document_id')->orderBy('c.position')->get();

        // Rank by the best distance seen in the neighbourhood, so a chunk
        // pulled in only as a neighbour inherits its anchor's relevance
        // instead of being dropped for having no distance of its own.
        $neighbourhoodDistance = [];

        foreach ($expanded as $row) {
            $own = $distanceByChunk[(int) $row->id] ?? null;

            if ($own !== null) {
                $neighbourhoodDistance[(int) $row->id] = $own;

                continue;
            }

            $best = 1.0;

            foreach ($rows as $anchor) {
                if ((int) $anchor->document_id !== (int) $row->document_id) {
                    continue;
                }

                if (abs((int) $anchor->position - (int) $row->position) <= 1) {
                    $best = min($best, (float) $anchor->distance + 0.01);
                }
            }

            $neighbourhoodDistance[(int) $row->id] = $best;
        }

        $ordered = $expanded->sortBy(fn ($row) => $neighbourhoodDistance[(int) $row->id] ?? 1.0)->values();

        // Fill the context budget in relevance order, then hand back the
        // survivors. Cutting here rather than in the prompt builder means the
        // citation list and the prompt can never disagree about what the model
        // actually saw.
        $budget = $this->maxContextTokens;
        $kept = [];

        foreach ($ordered as $row) {
            $tokens = (int) ($row->token_estimate ?: TokenCounter::estimate($row->content));

            if ($tokens > $budget) {
                continue;
            }

            $budget -= $tokens;

            $kept[] = new RetrievedChunk(
                chunkId: (int) $row->id,
                documentId: (int) $row->document_id,
                documentTitle: (string) $row->document_title,
                content: (string) $row->content,
                locator: $row->locator !== null ? (string) $row->locator : null,
                distance: round($neighbourhoodDistance[(int) $row->id] ?? 1.0, 4),
                tokenEstimate: $tokens,
            );
        }

        return $kept;
    }
}
