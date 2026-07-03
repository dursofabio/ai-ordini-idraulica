<?php

namespace App\Services\Ai;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * HTTP client for the OpenRouter (OpenAI-compatible) chat completions API.
 * All credentials, model, timeout, and retry behavior are read from
 * `config('services.openrouter.*')`; nothing is hardcoded. Requests that
 * exhaust their configured retries throw `Illuminate\Http\Client\RequestException`.
 */
class OpenRouterClient implements AiClient
{
    /**
     * Send a request to the chat completions API and return the parsed
     * response.
     *
     * @param  array<string, mixed>  $payload
     */
    public function messages(array $payload): ClaudeResponse
    {
        $response = $this->pendingRequest()->post('/chat/completions', $payload);

        return ClaudeResponse::fromOpenRouterResponse($response);
    }

    public function modelFast(): string
    {
        return (string) config('services.openrouter.model');
    }

    public function modelSmart(): string
    {
        return (string) config('services.openrouter.model');
    }

    /**
     * Build a pre-configured pending request with the OpenRouter auth
     * headers, base URL, timeout, and retry policy applied.
     */
    private function pendingRequest(): PendingRequest
    {
        return Http::withHeaders([
            'Authorization' => 'Bearer '.config('services.openrouter.api_key'),
            'content-type' => 'application/json',
        ])
            ->baseUrl(config('services.openrouter.base_url'))
            ->timeout(config('services.openrouter.timeout'))
            ->retry(
                config('services.openrouter.retry_times'),
                config('services.openrouter.retry_delay_ms'),
            );
    }
}
