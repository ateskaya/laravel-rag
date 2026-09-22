<?php

namespace IbrahimEnsar\Rag\Providers;

use IbrahimEnsar\Rag\Contracts\ChatProvider;
use IbrahimEnsar\Rag\Exceptions\ProviderFailed;
use IbrahimEnsar\Rag\Support\ChatResult;

class OpenAiChatProvider implements ChatProvider
{
    public function __construct(
        private readonly OpenAiClient $client,
        private readonly string $model,
        private readonly float $temperature,
        private readonly int $maxOutputTokens,
    ) {
    }

    public function complete(array $messages): ChatResult
    {
        $response = $this->client->post('chat/completions', [
            'model' => $this->model,
            'messages' => $messages,
            'temperature' => $this->temperature,
            'max_tokens' => $this->maxOutputTokens,
            // The answer is consumed as JSON by AnswerService. Asking for
            // JSON mode turns "the model wrapped its JSON in prose" from a
            // regular occurrence into an impossible one.
            'response_format' => ['type' => 'json_object'],
        ]);

        $choice = $response['choices'][0] ?? null;

        if (! is_array($choice) || ! isset($choice['message']['content'])) {
            throw new ProviderFailed('Provider returned no completion.');
        }

        return new ChatResult(
            content: (string) $choice['message']['content'],
            promptTokens: (int) ($response['usage']['prompt_tokens'] ?? 0),
            completionTokens: (int) ($response['usage']['completion_tokens'] ?? 0),
            model: (string) ($response['model'] ?? $this->model),
            finishReason: isset($choice['finish_reason']) ? (string) $choice['finish_reason'] : null,
        );
    }

    public function model(): string
    {
        return $this->model;
    }
}
