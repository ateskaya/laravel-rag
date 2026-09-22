<?php

namespace IbrahimEnsar\Rag\Contracts;

use IbrahimEnsar\Rag\Support\ChatResult;

interface ChatProvider
{
    /**
     * @param  list<array{role: string, content: string}>  $messages
     *
     * @throws \IbrahimEnsar\Rag\Exceptions\ProviderRateLimited
     * @throws \IbrahimEnsar\Rag\Exceptions\ProviderFailed
     */
    public function complete(array $messages): ChatResult;

    public function model(): string;
}
