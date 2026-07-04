<?php

namespace App\Models;

use Database\Factories\ProductEmbeddingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'product_id',
    'content',
    'content_hash',
    'model',
    'dimensions',
    'embedding',
])]
class ProductEmbedding extends Model
{
    /** @use HasFactory<ProductEmbeddingFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'dimensions' => 'integer',
        ];
    }

    /**
     * The product this embedding belongs to.
     *
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
