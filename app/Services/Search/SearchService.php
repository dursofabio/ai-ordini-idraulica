<?php

namespace App\Services\Search;

use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\ProductBase;
use App\Services\Ai\EmbeddingClient;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Hybrid search over product-bases: fuses a vector similarity score
 * (cosine, via pgvector) with a full-text score (Postgres tsvector) into a
 * single weighted ranking, grouped by `product_base_id`. An exact
 * `codice_articolo` match is always forced to the top of the results.
 *
 * The query embedding is cached (keyed by embedding model + query hash) to
 * avoid re-calling the embedding provider on repeated searches.
 */
class SearchService
{
    /**
     * The attribute key treated as the representative "power" attribute
     * when computing each result's power range (see AttributeResolver).
     */
    private const POWER_ATTRIBUTE_KEY = 'potenza_kw';

    public function __construct(
        private readonly EmbeddingClient $embeddingClient,
    ) {}

    /**
     * Search product-bases by free-text query, optionally narrowed by
     * structured filters. Results are ranked by the weighted fusion of
     * vector and full-text scores, grouped one row per product-base, and
     * enriched with `variants_count` and `power_range` grouping metadata.
     *
     * @param  array{brand_id?: int, family_id?: int, subfamily_id?: int, attributes?: array<int, array{key: string, min?: float, max?: float, value?: string}>}  $filters
     * @return Collection<int, SearchResult>
     */
    public function search(string $query, array $filters = []): Collection
    {
        $exactMatchBaseId = $this->findExactCodeMatchBaseId($query);

        $builder = $this->applyFilters(ProductBase::query(), $filters);
        $builder = $this->applyRanking($builder, $query, $exactMatchBaseId);
        $builder = $this->withGroupingMeta($builder);

        return $builder->get()->map(fn (ProductBase $productBase) => new SearchResult(
            productBase: $productBase,
            variantsCount: (int) $productBase->getAttribute('variants_count'),
            powerRangeMin: $productBase->getAttribute('power_range_min') !== null
                ? (float) $productBase->getAttribute('power_range_min')
                : null,
            powerRangeMax: $productBase->getAttribute('power_range_max') !== null
                ? (float) $productBase->getAttribute('power_range_max')
                : null,
        ));
    }

    /**
     * Add `variants_count` (number of Product variants belonging to the
     * base) and `power_range_min`/`power_range_max` (min/max `value_num` of
     * the representative power attribute across those variants) to each
     * row, via aggregated subselects — no N+1.
     *
     * @param  Builder<ProductBase>  $builder
     * @return Builder<ProductBase>
     */
    private function withGroupingMeta(Builder $builder): Builder
    {
        $variantIdsForBase = fn ($query) => $query
            ->select('products.id')
            ->from('products')
            ->whereColumn('products.product_base_id', 'product_bases.id');

        return $builder
            ->withCount('products as variants_count')
            ->selectSub(
                ProductAttribute::query()
                    ->selectRaw('MIN(value_num)')
                    ->where('key', self::POWER_ATTRIBUTE_KEY)
                    ->whereIn('product_id', $variantIdsForBase),
                'power_range_min',
            )
            ->selectSub(
                ProductAttribute::query()
                    ->selectRaw('MAX(value_num)')
                    ->where('key', self::POWER_ATTRIBUTE_KEY)
                    ->whereIn('product_id', $variantIdsForBase),
                'power_range_max',
            );
    }

    /**
     * Rank the (already filtered) product-base query by the weighted fusion
     * of vector similarity and full-text relevance, forcing the exact code
     * match (when any) to the very top.
     *
     * On PostgreSQL this joins `product_embeddings` (for the current
     * embedding model) and uses `search_vector`/`ts_rank` for the FTS score
     * and pgvector's `<=>` cosine distance operator for the vector score.
     * On any other driver (no tsvector/pgvector support) it falls back to a
     * plain `LIKE` match on title/description_ai, without vector fusion.
     *
     * @param  Builder<ProductBase>  $builder
     * @return Builder<ProductBase>
     */
    private function applyRanking(Builder $builder, string $query, ?int $exactMatchBaseId): Builder
    {
        $isExactMatch = $exactMatchBaseId !== null
            ? "CASE WHEN product_bases.id = {$exactMatchBaseId} THEN 1 ELSE 0 END"
            : 'CASE WHEN 1 = 0 THEN 1 ELSE 0 END';

        if (DB::connection()->getDriverName() !== 'pgsql') {
            $like = '%'.$query.'%';

            return $builder
                ->where(function (Builder $q) use ($like) {
                    $q->where('title', 'like', $like)
                        ->orWhere('description_ai', 'like', $like);
                })
                ->orderByRaw("{$isExactMatch} DESC")
                ->orderBy('title');
        }

        $model = config('services.embedding.model');
        $queryEmbedding = '['.implode(',', $this->embedQuery($query)).']';
        $vectorWeight = (float) config('search.weights.vector');
        $ftsWeight = (float) config('search.weights.fts');

        return $builder
            ->leftJoin('product_embeddings', function ($join) use ($model) {
                $join->on('product_embeddings.product_base_id', '=', 'product_bases.id')
                    ->where('product_embeddings.model', '=', $model);
            })
            ->select('product_bases.*')
            ->selectRaw(
                'ts_rank(product_bases.search_vector, plainto_tsquery(\'italian\', ?)) AS fts_score',
                [$query],
            )
            ->selectRaw(
                'COALESCE(1 - (product_embeddings.embedding <=> ?), 0) AS vector_score',
                [$queryEmbedding],
            )
            ->selectRaw(
                "({$vectorWeight} * COALESCE(1 - (product_embeddings.embedding <=> ?), 0)) "
                ."+ ({$ftsWeight} * ts_rank(product_bases.search_vector, plainto_tsquery('italian', ?))) AS combined_score",
                [$queryEmbedding, $query],
            )
            ->orderByRaw("{$isExactMatch} DESC")
            ->orderByRaw('combined_score DESC');
    }

    /**
     * Apply optional structured filters (brand, family, subfamily, attribute
     * constraints) to the base product-base query, before ranking. Each
     * attribute filter matches product-bases with at least one variant
     * satisfying the constraint.
     *
     * @param  Builder<ProductBase>  $builder
     * @param  array{brand_id?: int, family_id?: int, subfamily_id?: int, attributes?: array<int, array{key: string, min?: float, max?: float, value?: string}>}  $filters
     * @return Builder<ProductBase>
     */
    private function applyFilters(Builder $builder, array $filters): Builder
    {
        if (isset($filters['brand_id'])) {
            $builder->where('brand_id', $filters['brand_id']);
        }

        if (isset($filters['family_id'])) {
            $builder->where('family_id', $filters['family_id']);
        }

        if (isset($filters['subfamily_id'])) {
            $builder->where('subfamily_id', $filters['subfamily_id']);
        }

        foreach ($filters['attributes'] ?? [] as $attributeFilter) {
            $builder->whereHas(
                'products.attributes',
                fn (Builder $q) => $this->applyAttributeConstraint($q, $attributeFilter),
            );
        }

        return $builder;
    }

    /**
     * Constrain a product-attribute subquery to a single attribute filter,
     * on any attribute key: a numeric range on `value_num` (`min`/`max`,
     * either side may be omitted for an open-ended range) or an exact
     * case-insensitive match on `value_text` (`value`).
     *
     * @param  Builder<ProductAttribute>  $query
     * @param  array{key: string, min?: float, max?: float, value?: string}  $attributeFilter
     * @return Builder<ProductAttribute>
     */
    private function applyAttributeConstraint(Builder $query, array $attributeFilter): Builder
    {
        $query->where('key', $attributeFilter['key']);

        if (isset($attributeFilter['value'])) {
            return $query->whereRaw('LOWER(value_text) = ?', [mb_strtolower($attributeFilter['value'])]);
        }

        if (isset($attributeFilter['min'])) {
            $query->where('value_num', '>=', $attributeFilter['min']);
        }

        if (isset($attributeFilter['max'])) {
            $query->where('value_num', '<=', $attributeFilter['max']);
        }

        return $query;
    }

    /**
     * Resolve the `product_base_id` of a product whose `codice_articolo`
     * matches the query exactly (case-insensitive), if any. Used to force
     * an exact code match to the top of the results.
     */
    private function findExactCodeMatchBaseId(string $query): ?int
    {
        $trimmed = trim($query);

        if ($trimmed === '') {
            return null;
        }

        return Product::query()
            ->whereRaw('LOWER(codice_articolo) = ?', [mb_strtolower($trimmed)])
            ->whereNotNull('product_base_id')
            ->value('product_base_id');
    }

    /**
     * Get the embedding for the given query text, from cache when available.
     * Cache key is derived from the embedding model + query (md5), TTL is
     * read from `config('search.cache.ttl')`.
     *
     * @return array<int, float>
     */
    private function embedQuery(string $query): array
    {
        $model = config('services.embedding.model');
        $cacheKey = 'search:query-embedding:'.$model.':'.md5($query);

        return Cache::remember(
            $cacheKey,
            (int) config('search.cache.ttl'),
            fn () => $this->embeddingClient->embed($query),
        );
    }
}
