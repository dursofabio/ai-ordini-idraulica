<?php

namespace App\Models;

use App\Enums\ImportBatchStatus;
use Database\Factories\ImportBatchFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'filename',
    'hash',
    'status',
    'total_rows',
    'processed_rows',
    'error_rows',
    'skipped_rows',
    'started_at',
    'finished_at',
])]
class ImportBatch extends Model
{
    /** @use HasFactory<ImportBatchFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ImportBatchStatus::class,
            'total_rows' => 'integer',
            'processed_rows' => 'integer',
            'error_rows' => 'integer',
            'skipped_rows' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * The raw staging rows produced by this import batch.
     *
     * @return HasMany<StagingArticolo, $this>
     */
    public function stagingArticoli(): HasMany
    {
        return $this->hasMany(StagingArticolo::class);
    }
}
