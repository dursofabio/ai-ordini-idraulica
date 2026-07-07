<?php

namespace App\Exceptions;

use App\Services\Ai\DeepEnrichmentResponseValidator;
use RuntimeException;

/**
 * Thrown by {@see DeepEnrichmentResponseValidator} when a deep-enrichment AI
 * response is not usable: syntactically invalid JSON, a missing or
 * out-of-range confidence (overall or per attribute), an empty extended
 * description, or a malformed attribute entry. Callers (the deep enrichment
 * job) catch this to drive the retry-then-needs_review flow instead of
 * letting it bubble up as a failure.
 */
class InvalidDeepEnrichmentResponseException extends RuntimeException {}
