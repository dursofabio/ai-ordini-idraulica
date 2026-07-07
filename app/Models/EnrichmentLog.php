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
    'request_payload',
    'response_payload',
    'confidence',
    'model',
    'tokens_in',
    'tokens_out',
    'cost',
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
            'request_payload' => 'array',
            'response_payload' => 'array',
            'confidence' => 'integer',
            'tokens_in' => 'integer',
            'tokens_out' => 'integer',
            'cost' => 'decimal:6',
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
