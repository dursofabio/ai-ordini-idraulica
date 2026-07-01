<?php

namespace App\Exceptions;

use App\Services\Ai\ClassificationResponseValidator;
use RuntimeException;

/**
 * Thrown by {@see ClassificationResponseValidator} when an
 * AI classification response is not usable: syntactically invalid JSON, a
 * structure that does not match the expected schema, a missing result for
 * one of the requested products, or a brand/family/subfamily outside the
 * closed taxonomy. Callers (the classification job) catch this to drive the
 * retry-then-needs_review flow instead of letting it bubble up as a failure.
 */
class InvalidClassificationResponseException extends RuntimeException {}
