<?php

namespace Tests\Feature;

use App\Services\Ai\EmbeddingClient;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/**
 * US-017 acceptance criteria — self-hosted Ollama embedding HTTP client:
 *  - Endpoint/model/api key/dimensions/timeout/retry are read from
 *    config('services.embedding.*'), never hardcoded.
 *  - embed() posts to /api/embeddings with {model, prompt} and returns a
 *    float[] of the configured dimensions.
 *  - The Authorization header is present only when an api_key is configured.
 *  - A response whose 'embedding' length doesn't match the configured
 *    dimensions throws RuntimeException.
 *  - HTTP 4xx/5xx responses cause a RequestException after the configured
 *    number of retry attempts, without silently swallowing the error.
 */
class EmbeddingClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.embedding', [
            'base_url' => 'https://embedding.test',
            'model' => 'bge-m3',
            'api_key' => null,
            'dimensions' => 4,
            'timeout' => 120,
            'retry_times' => 2,
            'retry_delay_ms' => 1,
        ]);
    }

    public function test_embed_sends_configured_payload_without_auth_header_when_no_api_key(): void
    {
        Http::fake([
            '*' => Http::response(['embedding' => [0.1, 0.2, 0.3, 0.4]]),
        ]);

        (new EmbeddingClient)->embed('tubo in PVC 32mm');

        Http::assertSent(function ($request): bool {
            return $request->url() === 'https://embedding.test/api/embeddings'
                && $request->data() === ['model' => 'bge-m3', 'prompt' => 'tubo in PVC 32mm']
                && $request->hasHeader('content-type', 'application/json')
                && ! $request->hasHeader('Authorization');
        });
    }

    public function test_embed_sends_authorization_header_when_api_key_configured(): void
    {
        config()->set('services.embedding.api_key', 'test-embedding-key');

        Http::fake([
            '*' => Http::response(['embedding' => [0.1, 0.2, 0.3, 0.4]]),
        ]);

        (new EmbeddingClient)->embed('tubo in PVC 32mm');

        Http::assertSent(function ($request): bool {
            return $request->hasHeader('Authorization', 'Bearer test-embedding-key');
        });
    }

    public function test_embed_returns_float_array_from_valid_response(): void
    {
        Http::fake([
            '*' => Http::response(['embedding' => [0.1, 0.2, 0.3, 0.4]]),
        ]);

        $result = (new EmbeddingClient)->embed('tubo in PVC 32mm');

        $this->assertSame([0.1, 0.2, 0.3, 0.4], $result);
    }

    public function test_embed_throws_runtime_exception_on_dimension_mismatch(): void
    {
        Http::fake([
            '*' => Http::response(['embedding' => [0.1, 0.2]]),
        ]);

        $this->expectException(RuntimeException::class);

        (new EmbeddingClient)->embed('tubo in PVC 32mm');
    }

    public function test_embed_throws_request_exception_after_retries_on_server_error(): void
    {
        Http::fake([
            '*' => Http::sequence()
                ->push(['error' => 'server error'], 500)
                ->push(['error' => 'server error'], 500)
                ->push(['error' => 'server error'], 500),
        ]);

        $this->expectException(RequestException::class);

        try {
            (new EmbeddingClient)->embed('tubo in PVC 32mm');
        } finally {
            // retry_times=2 configured => 2 total attempts sent.
            Http::assertSentCount(2);
        }
    }

    public function test_embed_throws_request_exception_on_client_error(): void
    {
        Http::fake([
            '*' => Http::response(['error' => 'unauthorized'], 401),
        ]);

        $this->expectException(RequestException::class);

        try {
            (new EmbeddingClient)->embed('tubo in PVC 32mm');
        } finally {
            // retry_times=2 configured => 2 total attempts sent.
            Http::assertSentCount(2);
        }
    }
}
