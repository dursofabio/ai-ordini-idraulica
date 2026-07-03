<?php

namespace App\Services\Ai;

use App\Jobs\ClassifyProductsBatchJob;

/**
 * Common abstraction over the AI providers usable for product
 * classification (Anthropic Claude, OpenRouter, ...), so
 * {@see ClassifyProductsBatchJob} can call the active provider
 * without depending on a concrete client class.
 */
interface AiClient
{
    /**
     * Send a classification request and return the parsed response.
     *
     * @param  array<string, mixed>  $payload
     */
    public function messages(array $payload): ClaudeResponse;

    /**
     * The model identifier to use for the bulk (fast) classification call.
     */
    public function modelFast(): string;

    /**
     * The model identifier to use for per-product low-confidence escalation.
     */
    public function modelSmart(): string;
}
