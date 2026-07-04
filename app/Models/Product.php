<?php

namespace App\Models;

use App\Jobs\GenerateProductEmbeddingJob;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'codice_articolo',
    'description_raw',
    'description_clean',
    'product_type',
    'descrizione_estesa',
    'descrizione_marca',
    'marca_codice',
    'fam_codice',
    'fam_descrizione',
    'subfam_codice',
    'subfam_descrizione',
    'costo',
    'giacenza',
    'is_active',
    'enrichment_status',
    'brand_id',
    'family_id',
    'subfamily_id',
    'brand_source',
    'family_source',
    'subfamily_source',
    'source',
    'confidence',
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

    /**
     * The enrichment proposal entries for this product.
     *
     * @return HasMany<EnrichmentProposal, $this>
     */
    public function enrichmentProposals(): HasMany
    {
        return $this->hasMany(EnrichmentProposal::class);
    }

    /**
     * The vector embedding generated from this product's own type + brand
     * (or description_clean, see {@see composeEmbeddingContent()}).
     *
     * @return HasOne<ProductEmbedding, $this>
     */
    public function embedding(): HasOne
    {
        return $this->hasOne(ProductEmbedding::class);
    }

    /**
     * Deterministically compose the text fed to the embedding provider for
     * this single product: `product_type` + brand name when `product_type`
     * is set, falling back to `description_clean` otherwise (US-046 AC2).
     * Variants of the same model share an identical `product_type` + brand,
     * so they naturally compose the same content — deduplicated by content
     * hash in {@see GenerateProductEmbeddingJob} so they don't pay
     * for a duplicate embedding call.
     */
    public function composeEmbeddingContent(): string
    {
        if (trim((string) $this->product_type) !== '') {
            return trim(implode(' ', array_filter([
                $this->product_type,
                $this->brand?->name,
            ])));
        }

        return trim((string) $this->description_clean);
    }
}
