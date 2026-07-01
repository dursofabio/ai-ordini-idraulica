<?php

namespace App\Models;

use Database\Factories\ProductBaseFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['title', 'grouping_key', 'brand_id', 'family_id', 'subfamily_id'])]
class ProductBase extends Model
{
    /** @use HasFactory<ProductBaseFactory> */
    use HasFactory;

    /**
     * The products (variants) that belong to this base.
     *
     * @return HasMany<Product, $this>
     */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    /**
     * The brand this base belongs to (nullable).
     *
     * @return BelongsTo<Brand, $this>
     */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /**
     * The family this base belongs to (nullable).
     *
     * @return BelongsTo<Family, $this>
     */
    public function family(): BelongsTo
    {
        return $this->belongsTo(Family::class);
    }

    /**
     * The subfamily this base belongs to (nullable).
     *
     * @return BelongsTo<Subfamily, $this>
     */
    public function subfamily(): BelongsTo
    {
        return $this->belongsTo(Subfamily::class);
    }
}
