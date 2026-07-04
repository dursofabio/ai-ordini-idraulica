<?php

namespace App\Exceptions;

use App\Services\Ai\QueryParseResponseValidator;
use App\Services\Search\QueryParser;
use RuntimeException;

/**
 * Thrown by {@see QueryParseResponseValidator} when a natural-language
 * query-parse AI response is not usable: syntactically invalid JSON, or a
 * structure missing the required `recognized_text` field. The caller
 * ({@see QueryParser}) catches this and falls back to
 * treating the whole original query as plain text — a parsing failure must
 * never break the search itself.
 */
class InvalidQueryParseResponseException extends RuntimeException {}
