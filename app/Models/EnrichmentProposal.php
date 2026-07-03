<?php

namespace App\Models;

use Database\Factories\EnrichmentProposalFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'product_id',
    'field',
    'attribute_key',
    'value_id',
    'value_num',
    'value_text',
    'unit',
    'origin',
    'confidence',
    'status',
])]
class EnrichmentProposal extends Model
{
    /** @use HasFactory<EnrichmentProposalFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'value_num' => 'decimal:3',
            'confidence' => 'integer',
        ];
    }

    /**
     * The product this enrichment proposal belongs to.
     *
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
