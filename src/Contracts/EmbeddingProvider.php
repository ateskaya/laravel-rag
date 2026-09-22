<?php

namespace IbrahimEnsar\Rag\Contracts;

use IbrahimEnsar\Rag\Support\EmbeddingResult;

interface EmbeddingProvider
{
    /**
     * Embed a batch of strings, preserving order.
     *
     * @param  list<string>  $texts
     *
     * @throws \IbrahimEnsar\Rag\Exceptions\ProviderRateLimited
     * @throws \IbrahimEnsar\Rag\Exceptions\ProviderFailed
     */
    public function embed(array $texts): EmbeddingResult;

    public function model(): string;

    public function dimensions(): int;
}
