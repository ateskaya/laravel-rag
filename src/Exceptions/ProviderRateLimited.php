<?php

namespace IbrahimEnsar\Rag\Exceptions;

/**
 * Separated from ProviderFailed because the two call for opposite responses:
 * a rate limit means "the same request will succeed later, wait and retry",
 * while a 400 means "this request will never succeed, stop retrying it".
 */
class ProviderRateLimited extends ProviderFailed
{
    public function __construct(
        string $message,
        public readonly ?int $retryAfterSeconds = null,
        ?int $statusCode = null,
    ) {
        parent::__construct($message, $statusCode, 'rate_limited');
    }
}
