<?php

namespace App\Services\Ai;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * HTTP client for the self-hosted Ollama embedding API (model `bge-m3` by
 * default). Endpoint, model, credentials, timeout, and retry behavior are
 * all read from `config('services.embedding.*')`; nothing is hardcoded.
 * Requests that exhaust their configured retries throw
 * `Illuminate\Http\Client\RequestException`.
 */
class EmbeddingClient
{
    /**
     * Vectorize the given text and return the embedding as a list of floats.
     *
     * @return array<int, float>
     */
    public function embed(string $text): array
    {
        $response = $this->pendingRequest()->post('/api/embeddings', [
            'model' => config('services.embedding.model'),
            'prompt' => $text,
        ]);

        $embedding = $response->json('embedding');

        $expectedDimensions = (int) config('services.embedding.dimensions');

        if (! is_array($embedding) || count($embedding) !== $expectedDimensions) {
            throw new RuntimeException(sprintf(
                'Embedding provider returned %d dimensions, expected %d.',
                is_array($embedding) ? count($embedding) : 0,
                $expectedDimensions,
            ));
        }

        return array_map(static fn (mixed $value): float => (float) $value, $embedding);
    }

    /**
     * Build a pre-configured pending request with the embedding provider's
     * auth header (only when an API key is configured), base URL, timeout,
     * and retry policy applied.
     */
    private function pendingRequest(): PendingRequest
    {
        $apiKey = config('services.embedding.api_key');

        $request = Http::withHeaders(array_filter([
            'content-type' => 'application/json',
            'Authorization' => $apiKey ? 'Bearer '.$apiKey : null,
        ]));

        return $request
            ->baseUrl(config('services.embedding.base_url'))
            ->timeout(config('services.embedding.timeout'))
            ->retry(
                config('services.embedding.retry_times'),
                config('services.embedding.retry_delay_ms'),
            );
    }
}
