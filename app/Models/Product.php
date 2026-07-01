<?php

namespace App\Models;

use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'codice_articolo',
    'description_raw',
    'description_clean',
    'descrizione_marca',
    'costo',
    'giacenza',
    'is_active',
    'enrichment_status',
    'product_base_id',
    'brand_id',
    'family_id',
    'subfamily_id',
    'brand_source',
    'family_source',
    'subfamily_source',
    'source',
    'confidence',
    'grouping_key',
])]
class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'costo' => 'decimal:2',
            'giacenza' => 'decimal:2',
            'is_active' => 'boolean',
            'confidence' => 'integer',
        ];
    }

    /**
     * The base (variant group) this product belongs to (nullable).
     *
     * @return BelongsTo<ProductBase, $this>
     */
    public function productBase(): BelongsTo
    {
        return $this->belongsTo(ProductBase::class);
    }

    /**
     * The brand this product belongs to (nullable).
     *
     * @return BelongsTo<Brand, $this>
     */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /**
     * The family this product belongs to (nullable).
     *
     * @return BelongsTo<Family, $this>
     */
    public function family(): BelongsTo
    {
        return $this->belongsTo(Family::class);
    }

    /**
     * The subfamily this product belongs to (nullable).
     *
     * @return BelongsTo<Subfamily, $this>
     */
    public function subfamily(): BelongsTo
    {
        return $this->belongsTo(Subfamily::class);
    }

    /**
     * The technical attributes of this product.
     *
     * @return HasMany<ProductAttribute, $this>
     */
    public function attributes(): HasMany
    {
        return $this->hasMany(ProductAttribute::class);
    }

    /**
     * The enrichment audit log entries for this product.
     *
     * @return HasMany<EnrichmentLog, $this>
     */
    public function enrichmentLogs(): HasMany
    {
        return $this->hasMany(EnrichmentLog::class);
    }
}
