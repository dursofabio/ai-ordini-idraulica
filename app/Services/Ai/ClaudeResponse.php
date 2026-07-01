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
    ) {}

    /**
     * Build a ClaudeResponse from a raw Anthropic Messages API HTTP response,
     * extracting the first text content block and the input/output token
     * usage.
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
}
