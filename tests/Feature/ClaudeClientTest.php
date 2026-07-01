<?php

namespace Tests\Feature;

use App\Services\Ai\ClaudeClient;
use App\Services\Ai\ClaudeResponse;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * US-013 acceptance criteria — Claude HTTP client:
 *  - Model/api key/version/base URL/timeout/retry are read from
 *    config('services.anthropic.*'), never hardcoded.
 *  - messages() sends the x-api-key and anthropic-version headers and
 *    returns a ClaudeResponse with content and token usage parsed from
 *    the Anthropic response body.
 *  - batch() posts to /v1/messages/batches with the same headers/payload.
 *  - HTTP 4xx/5xx responses cause a RequestException after the configured
 *    number of retry attempts, without silently swallowing the error.
 */
class ClaudeClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.anthropic', [
            'api_key' => 'test-api-key',
            'model' => 'claude-test-model',
            'version' => '2023-06-01',
            'base_url' => 'https://api.anthropic.test',
            'timeout' => 120,
            'retry_times' => 2,
            'retry_delay_ms' => 1,
        ]);
    }

    public function test_messages_sends_configured_headers(): void
    {
        Http::fake([
            '*' => Http::response([
                'content' => [['type' => 'text', 'text' => 'ciao']],
                'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
            ]),
        ]);

        (new ClaudeClient)->messages(['model' => 'claude-test-model', 'messages' => []]);

        Http::assertSent(function ($request): bool {
            return $request->url() === 'https://api.anthropic.test/v1/messages'
                && $request->hasHeader('x-api-key', 'test-api-key')
                && $request->hasHeader('anthropic-version', '2023-06-01')
                && $request->hasHeader('content-type', 'application/json');
        });
    }

    public function test_messages_parses_content_and_token_usage(): void
    {
        Http::fake([
            '*' => Http::response([
                'content' => [['type' => 'text', 'text' => 'Risposta AI generata']],
                'usage' => ['input_tokens' => 42, 'output_tokens' => 17],
            ]),
        ]);

        $response = (new ClaudeClient)->messages(['model' => 'claude-test-model', 'messages' => []]);

        $this->assertInstanceOf(ClaudeResponse::class, $response);
        $this->assertSame('Risposta AI generata', $response->content);
        $this->assertSame(42, $response->tokensIn);
        $this->assertSame(17, $response->tokensOut);
    }

    public function test_messages_throws_request_exception_after_retries_on_server_error(): void
    {
        Http::fake([
            '*' => Http::sequence()
                ->push(['error' => 'server error'], 500)
                ->push(['error' => 'server error'], 500)
                ->push(['error' => 'server error'], 500),
        ]);

        $this->expectException(RequestException::class);

        try {
            (new ClaudeClient)->messages(['model' => 'claude-test-model', 'messages' => []]);
        } finally {
            // retry_times=2 configured => 2 total attempts sent.
            Http::assertSentCount(2);
        }
    }

    public function test_messages_throws_request_exception_on_client_error(): void
    {
        Http::fake([
            '*' => Http::response(['error' => 'unauthorized'], 401),
        ]);

        $this->expectException(RequestException::class);

        try {
            (new ClaudeClient)->messages(['model' => 'claude-test-model', 'messages' => []]);
        } finally {
            // retry_times=2 configured => 2 total attempts sent.
            Http::assertSentCount(2);
        }
    }

    public function test_batch_sends_request_to_batches_endpoint_with_configured_headers(): void
    {
        Http::fake([
            '*' => Http::response([
                'content' => [],
                'usage' => ['input_tokens' => 0, 'output_tokens' => 0],
            ]),
        ]);

        $payload = ['requests' => [['custom_id' => '1', 'params' => ['model' => 'claude-test-model']]]];

        (new ClaudeClient)->batch($payload);

        Http::assertSent(function ($request) use ($payload): bool {
            return $request->url() === 'https://api.anthropic.test/v1/messages/batches'
                && $request->hasHeader('x-api-key', 'test-api-key')
                && $request->hasHeader('anthropic-version', '2023-06-01')
                && $request->data() === $payload;
        });
    }
}
