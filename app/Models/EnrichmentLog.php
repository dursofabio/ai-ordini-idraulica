<?php

namespace App\Models;

use Database\Factories\EnrichmentLogFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'product_id',
    'step',
    'input',
    'output',
    'confidence',
    'model',
    'tokens_in',
    'tokens_out',
])]
class EnrichmentLog extends Model
{
    /** @use HasFactory<EnrichmentLogFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'input' => 'array',
            'output' => 'array',
            'confidence' => 'integer',
            'tokens_in' => 'integer',
            'tokens_out' => 'integer',
        ];
    }

    /**
     * The product this enrichment log entry belongs to.
     *
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
