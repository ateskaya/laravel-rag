<?php

namespace IbrahimEnsar\Rag\Providers;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use IbrahimEnsar\Rag\Exceptions\ProviderFailed;
use IbrahimEnsar\Rag\Exceptions\ProviderRateLimited;
use JsonException;
use Psr\Http\Message\ResponseInterface;

/**
 * Thin transport shared by the embedding and chat providers.
 *
 * It does one job beyond issuing the request: translating HTTP failures into
 * the two exception types the rest of the package understands, so that callers
 * never have to reason about status codes to decide whether retrying is
 * pointless or mandatory.
 */
class OpenAiClient
{
    public function __construct(
        private readonly Client $http,
        private readonly string $apiKey,
    ) {
    }

    public static function fromConfig(array $config): self
    {
        $key = $config['api_key'] ?? null;

        if (! is_string($key) || $key === '') {
            throw new ProviderFailed('OPENAI_API_KEY is not set.');
        }

        return new self(
            new Client([
                'base_uri' => $config['base_uri'] ?? 'https://api.openai.com/v1/',
                'timeout' => $config['timeout'] ?? 60,
                'connect_timeout' => $config['connect_timeout'] ?? 10,
                'http_errors' => false,
            ]),
            $key,
        );
    }

    public function post(string $path, array $payload): array
    {
        try {
            $response = $this->http->post($path, [
                'headers' => [
                    'Authorization' => 'Bearer '.$this->apiKey,
                    'Content-Type' => 'application/json',
                ],
                'body' => json_encode($payload, JSON_THROW_ON_ERROR),
            ]);
        } catch (ConnectException $e) {
            // Never reached the provider: always worth retrying.
            throw new ProviderRateLimited('Could not reach the provider: '.$e->getMessage());
        } catch (RequestException $e) {
            throw new ProviderFailed('Request to the provider failed: '.$e->getMessage());
        } catch (JsonException $e) {
            throw new ProviderFailed('Could not encode the request body: '.$e->getMessage());
        }

        return $this->decode($response);
    }

    private function decode(ResponseInterface $response): array
    {
        $status = $response->getStatusCode();
        $body = (string) $response->getBody();

        if ($status === 429 || $status >= 500) {
            throw new ProviderRateLimited(
                $this->errorMessage($body) ?? "Provider returned HTTP {$status}.",
                $this->retryAfter($response),
                $status,
            );
        }

        if ($status >= 400) {
            // 400/401/403/404 will fail identically on every retry. Surfacing
            // them as ProviderFailed is what stops the queue from burning its
            // attempts on a request that can never succeed.
            throw new ProviderFailed(
                $this->errorMessage($body) ?? "Provider returned HTTP {$status}.",
                $status,
            );
        }

        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new ProviderFailed('Provider returned a body that is not JSON: '.$e->getMessage());
        }

        if (! is_array($decoded)) {
            throw new ProviderFailed('Provider returned an unexpected body shape.');
        }

        return $decoded;
    }

    private function retryAfter(ResponseInterface $response): ?int
    {
        $header = $response->getHeaderLine('retry-after');

        return is_numeric($header) ? (int) $header : null;
    }

    private function errorMessage(string $body): ?string
    {
        $decoded = json_decode($body, true);

        return is_array($decoded) && isset($decoded['error']['message'])
            ? (string) $decoded['error']['message']
            : null;
    }
}
