<?php

namespace App\Exceptions;

use App\Enums\ImportBatchStatus;
use RuntimeException;

/**
 * Thrown when an import batch is asked to move to a status that is not
 * reachable from its current one (per {@see ImportBatchStatus::canTransitionTo()}).
 */
class InvalidStatusTransitionException extends RuntimeException
{
    public function __construct(
        public readonly ImportBatchStatus $from,
        public readonly ImportBatchStatus $to,
    ) {
        parent::__construct(
            "Transizione di stato non ammessa: {$from->value} → {$to->value}."
        );
    }
}
