<?php

namespace App\Services;

use App\Enums\ImportBatchStatus;
use App\Exceptions\DuplicateImportException;
use App\Exceptions\InvalidStatusTransitionException;
use App\Models\ImportBatch;
use InvalidArgumentException;

/**
 * Orchestrates the import batch lifecycle: creating a batch from an XLSX file,
 * deduplicating by content hash, and advancing the batch through its states.
 *
 * The actual reading of the XLSX rows into staging is out of scope here (US-007);
 * this service only owns batch creation, deduplication and status transitions.
 */
class ImportBatchService
{
    /**
     * Start an import for the file at the given path.
     *
     * Computes the MD5 hash of the file, blocks the import if a completed batch
     * with the same hash already exists, and otherwise creates a new batch in
     * the `uploaded` state.
     *
     * @throws InvalidArgumentException when the path is not a readable file
     * @throws DuplicateImportException when the file was already imported successfully
     */
    public function startImport(string $path): ImportBatch
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new InvalidArgumentException("File non trovato o non leggibile: {$path}");
        }

        $hash = md5_file($path);

        $existing = ImportBatch::query()
            ->where('hash', $hash)
            ->where('status', ImportBatchStatus::Completed)
            ->first();

        if ($existing !== null) {
            throw new DuplicateImportException($existing);
        }

        return ImportBatch::create([
            'filename' => basename($path),
            'hash' => $hash,
            'status' => ImportBatchStatus::Uploaded,
            'started_at' => now(),
        ]);
    }

    public function markImporting(ImportBatch $batch): ImportBatch
    {
        return $this->transitionTo($batch, ImportBatchStatus::Importing);
    }

    public function markEnriching(ImportBatch $batch): ImportBatch
    {
        return $this->transitionTo($batch, ImportBatchStatus::Enriching);
    }

    public function markCompleted(ImportBatch $batch): ImportBatch
    {
        return $this->transitionTo($batch, ImportBatchStatus::Completed);
    }

    public function markFailed(ImportBatch $batch): ImportBatch
    {
        return $this->transitionTo($batch, ImportBatchStatus::Failed);
    }

    /**
     * Move the batch to the given status, validating the transition against the
     * lifecycle graph. Terminal states record the finish time.
     *
     * @throws InvalidStatusTransitionException when the transition is not allowed
     */
    public function transitionTo(ImportBatch $batch, ImportBatchStatus $next): ImportBatch
    {
        $current = $batch->status;

        if (! $current->canTransitionTo($next)) {
            throw new InvalidStatusTransitionException($current, $next);
        }

        $batch->status = $next;

        if ($next->isTerminal()) {
            $batch->finished_at = now();
        }

        $batch->save();

        return $batch;
    }
}
