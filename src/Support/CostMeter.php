<?php

namespace IbrahimEnsar\Rag\Support;

/**
 * Turns provider-reported token usage into a USD figure.
 *
 * Two rules here are deliberate:
 *
 *  - Token counts come from the provider's usage block, never from
 *    TokenCounter. An estimate is fine for sizing a prompt and useless for
 *    a number someone will reconcile against an invoice.
 *
 *  - Every amount is stored with the price list that produced it. Prices
 *    change; without the snapshot, last quarter's cost rows silently become
 *    unexplainable.
 */
final class CostMeter
{
    private float $total = 0.0;

    /** @var array<string, array{input: float, output: float}> */
    private array $used = [];

    /**
     * @param  array<string, array{input: float, output: float}>  $pricing  USD per 1M tokens.
     */
    public function __construct(private readonly array $pricing)
    {
    }

    public function record(string $model, int $inputTokens, int $outputTokens = 0): void
    {
        $rates = $this->pricing[$model] ?? null;

        if ($rates === null) {
            // An unpriced model is a configuration gap, not a reason to fail a
            // user's request. Record zero and leave the model out of the
            // snapshot so the gap is visible in the stored row.
            return;
        }

        $this->used[$model] = $rates;

        $this->total +=
            ($inputTokens / 1_000_000) * $rates['input']
            + ($outputTokens / 1_000_000) * $rates['output'];
    }

    public function totalUsd(): float
    {
        return round($this->total, 6);
    }

    /**
     * @return array<string, array{input: float, output: float}>
     */
    public function snapshot(): array
    {
        return $this->used;
    }
}
