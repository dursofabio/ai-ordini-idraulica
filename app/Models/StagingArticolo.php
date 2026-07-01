<?php

namespace App\Models;

use Database\Factories\StagingArticoloFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'import_batch_id',
    'raw_row',
    'row_number',
    'codice_articolo',
    'descrizione',
    'costo',
    'giacenza',
    'status',
    'error',
])]
class StagingArticolo extends Model
{
    /** @use HasFactory<StagingArticoloFactory> */
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'staging_articoli';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'raw_row' => 'array',
            'row_number' => 'integer',
            'costo' => 'decimal:4',
            'giacenza' => 'decimal:3',
        ];
    }

    /**
     * The import batch this staging row belongs to (nullable).
     *
     * @return BelongsTo<ImportBatch, $this>
     */
    public function importBatch(): BelongsTo
    {
        return $this->belongsTo(ImportBatch::class);
    }
}
