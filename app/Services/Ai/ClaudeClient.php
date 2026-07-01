<?php

namespace App\Services\Ai;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * HTTP client for the Anthropic Claude Messages and Batch APIs. All
 * credentials, model, timeout, and retry behavior are read from
 * `config('services.anthropic.*')`; nothing is hardcoded. Requests that
 * exhaust their configured retries throw `Illuminate\Http\Client\RequestException`.
 */
class ClaudeClient
{
    /**
     * Send a request to the Messages API and return the parsed response.
     *
     * @param  array<string, mixed>  $payload
     */
    public function messages(array $payload): ClaudeResponse
    {
        $response = $this->pendingRequest()->post('/v1/messages', $payload);

        return ClaudeResponse::fromHttpResponse($response);
    }

    /**
     * Send a request to the Batch API and return the parsed response.
     *
     * @param  array<string, mixed>  $payload
     */
    public function batch(array $payload): ClaudeResponse
    {
        $response = $this->pendingRequest()->post('/v1/messages/batches', $payload);

        return ClaudeResponse::fromHttpResponse($response);
    }

    /**
     * Build a pre-configured pending request with the Anthropic auth
     * headers, base URL, timeout, and retry policy applied.
     */
    private function pendingRequest(): PendingRequest
    {
        return Http::withHeaders([
            'x-api-key' => config('services.anthropic.api_key'),
            'anthropic-version' => config('services.anthropic.version'),
            'content-type' => 'application/json',
        ])
            ->baseUrl(config('services.anthropic.base_url'))
            ->timeout(config('services.anthropic.timeout'))
            ->retry(
                config('services.anthropic.retry_times'),
                config('services.anthropic.retry_delay_ms'),
            );
    }
}
