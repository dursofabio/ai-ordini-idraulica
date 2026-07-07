<?php

namespace App\Services\Ai;

use Illuminate\Http\Client\Response;

/**
 * Parsed result of a Claude Messages/Batch API call: the extracted text
 * content, token usage for cost tracking, and the raw decoded body for
 * callers that need fields this DTO does not expose.
 */
final readonly class ClaudeResponse
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public string $content,
        public int $tokensIn,
        public int $tokensOut,
        public array $raw,
        public ?float $cost = null,
    ) {}

    /**
     * Build a ClaudeResponse from a raw Anthropic Messages API HTTP response,
     * extracting the first text content block and the input/output token
     * usage. The Anthropic API does not report a per-request cost, so `cost`
     * is always null.
     */
    public static function fromHttpResponse(Response $response): self
    {
        $body = $response->json() ?? [];

        $content = collect($body['content'] ?? [])
            ->first(fn (array $block): bool => ($block['type'] ?? null) === 'text')['text'] ?? '';

        return new self(
            content: $content,
            tokensIn: (int) ($body['usage']['input_tokens'] ?? 0),
            tokensOut: (int) ($body['usage']['output_tokens'] ?? 0),
            raw: $body,
        );
    }

    /**
     * Build a ClaudeResponse from a raw OpenRouter (OpenAI-compatible) chat
     * completions HTTP response, extracting the first choice's message
     * content, the prompt/completion token usage, and the actual USD cost
     * OpenRouter billed for the request (`usage.cost`).
     */
    public static function fromOpenRouterResponse(Response $response): self
    {
        $body = $response->json() ?? [];

        $content = $body['choices'][0]['message']['content'] ?? '';

        return new self(
            content: $content,
            tokensIn: (int) ($body['usage']['prompt_tokens'] ?? 0),
            tokensOut: (int) ($body['usage']['completion_tokens'] ?? 0),
            raw: $body,
            cost: isset($body['usage']['cost']) ? (float) $body['usage']['cost'] : null,
        );
    }
}
