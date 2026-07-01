<?php

namespace App\Exceptions;

use App\Models\ImportBatch;
use RuntimeException;

/**
 * Thrown when an import is started for a file whose hash already matches a
 * previously completed batch. The already-completed batch is attached so the
 * caller can report it without a second lookup.
 */
class DuplicateImportException extends RuntimeException
{
    public function __construct(public readonly ImportBatch $existingBatch)
    {
        parent::__construct(
            "File già importato: un batch completato con hash {$existingBatch->hash} esiste già (#{$existingBatch->id})."
        );
    }
}
