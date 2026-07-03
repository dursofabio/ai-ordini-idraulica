<?php

namespace Tests\Feature;

use App\Services\Ai\ClaudeResponse;
use App\Services\Ai\OpenRouterClient;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * US-034 acceptance criteria — OpenRouter HTTP client:
 *  - API key/model/base URL/timeout/retry are read from
 *    config('services.openrouter.*'), never hardcoded.
 *  - messages() sends the Authorization bearer header and returns a
 *    ClaudeResponse with content and token usage parsed from the
 *    OpenAI-compatible response body.
 *  - HTTP 4xx/5xx responses cause a RequestException after the configured
 *    number of retry attempts, without a fallback to another provider.
 *  - modelFast()/modelSmart() both resolve to config('services.openrouter.model').
 */
class OpenRouterClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.openrouter', [
            'api_key' => 'test-openrouter-key',
            'model' => 'openrouter-test-model',
            'base_url' => 'https://openrouter.test/api/v1',
            'timeout' => 120,
            'retry_times' => 2,
            'retry_delay_ms' => 1,
        ]);
    }

    public function test_messages_sends_configured_headers(): void
    {
        Http::fake([
            '*' => Http::response([
                'choices' => [['message' => ['content' => 'ciao']]],
                'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1],
            ]),
        ]);

        (new OpenRouterClient)->messages(['model' => 'openrouter-test-model', 'messages' => []]);

        Http::assertSent(function ($request): bool {
            return $request->url() === 'https://openrouter.test/api/v1/chat/completions'
                && $request->hasHeader('Authorization', 'Bearer test-openrouter-key')
                && $request->hasHeader('content-type', 'application/json');
        });
    }

    public function test_messages_parses_content_and_token_usage(): void
    {
        Http::fake([
            '*' => Http::response([
                'choices' => [['message' => ['content' => 'Risposta AI generata']]],
                'usage' => ['prompt_tokens' => 42, 'completion_tokens' => 17],
            ]),
        ]);

        $response = (new OpenRouterClient)->messages(['model' => 'openrouter-test-model', 'messages' => []]);

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
            (new OpenRouterClient)->messages(['model' => 'openrouter-test-model', 'messages' => []]);
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
            (new OpenRouterClient)->messages(['model' => 'openrouter-test-model', 'messages' => []]);
        } finally {
            // retry_times=2 configured => 2 total attempts sent.
            Http::assertSentCount(2);
        }
    }

    public function test_model_fast_and_model_smart_both_resolve_to_the_configured_model(): void
    {
        $client = new OpenRouterClient;

        $this->assertSame('openrouter-test-model', $client->modelFast());
        $this->assertSame('openrouter-test-model', $client->modelSmart());
    }
}
