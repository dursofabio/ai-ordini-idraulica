<?php

namespace App\Enums;

/**
 * Lifecycle states of an import batch.
 *
 * The batch advances uploaded → importing → enriching → completed, with
 * failed reachable from the two working states. The allowed-transition graph
 * is encoded in {@see self::canTransitionTo()} so the domain layer can reject
 * illegal jumps (e.g. uploaded → completed) instead of silently accepting them.
 */
enum ImportBatchStatus: string
{
    case Uploaded = 'uploaded';
    case Importing = 'importing';
    case Enriching = 'enriching';
    case Completed = 'completed';
    case Failed = 'failed';

    /**
     * Whether this status may transition to the given next status.
     */
    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedNext(), true);
    }

    /**
     * Whether this is a terminal status (no further transitions allowed).
     */
    public function isTerminal(): bool
    {
        return $this->allowedNext() === [];
    }

    /**
     * The statuses reachable from the current one.
     *
     * @return array<int, self>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Uploaded => [self::Importing],
            self::Importing => [self::Enriching, self::Failed],
            self::Enriching => [self::Completed, self::Failed],
            self::Completed, self::Failed => [],
        };
    }
}
