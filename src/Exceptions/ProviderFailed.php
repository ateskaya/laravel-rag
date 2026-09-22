<?php

namespace IbrahimEnsar\Rag\Exceptions;

use RuntimeException;

class ProviderFailed extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $statusCode = null,
        public readonly ?string $providerCode = null,
    ) {
        parent::__construct($message);
    }
}
