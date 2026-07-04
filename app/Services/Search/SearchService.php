<?php

namespace App\Services\Search;

use App\Models\Product;
use App\Models\ProductAttribute;
use App\Services\Ai\EmbeddingClient;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Hybrid search over products (one row per SKU, no grouping): fuses a
 * vector similarity score (cosine, via pgvector) with a full-text score
 * (Postgres tsvector) into a single weighted ranking. An exact
 * `codice_articolo` match is always forced to the top of the results.
 *
 * The query embedding is cached (keyed by embedding model + query hash) to
 * avoid re-calling the embedding provider on repeated searches.
 */
class SearchService
{
    public function __construct(
        private readonly EmbeddingClient $embeddingClient,
    ) {}

    /**
     * Search products by free-text query, optionally narrowed by structured
     * filters. Results are ranked by the weighted fusion of vector and
     * full-text scores, one row per product.
     *
     * @param  array{brand_id?: int, family_id?: int, subfamily_id?: int, attributes?: array<int, array{key: string, min?: float, max?: float, value?: string}>}  $filters
     * @return Collection<int, SearchResult>
     */
    public function search(string $query, array $filters = []): Collection
    {
        $exactMatchProductId = $this->findExactCodeMatchProductId($query);

        return $this->buildSearchQuery($query, $filters, $exactMatchProductId)->get()
            ->map(fn (Product $product): SearchResult => $this->mapToResult($product, $exactMatchProductId));
    }

    /**
     * Same ranking/filtering as {@see search()}, but paginated at the SQL
     * level (`LIMIT`/`OFFSET`) instead of loading and mapping every matching
     * row just to serve one page of results — used by the backoffice
     * results table (US-033) to keep page loads fast regardless of how many
     * products a broad query matches.
     *
     * @param  array{brand_id?: int, family_id?: int, subfamily_id?: int, attributes?: array<int, array{key: string, min?: float, max?: float, value?: string}>}  $filters
     * @return LengthAwarePaginator<int, SearchResult>
     */
    public function paginate(string $query, array $filters, int $perPage, int $page): LengthAwarePaginator
    {
        $exactMatchProductId = $this->findExactCodeMatchProductId($query);

        $paginator = $this->buildSearchQuery($query, $filters, $exactMatchProductId)->paginate($perPage, ['*'], 'page', $page);

        return $paginator->setCollection(
            $paginator->getCollection()->mapWithKeys(fn (Product $product): array => [
                $product->id => $this->mapToResult($product, $exactMatchProductId),
            ]),
        );
    }

    /**
     * Same ranking/filtering as {@see search()}, capped to the top
     * `$limit` candidates with no offset — used by
     * {@see MatchOutcomeResolver} (US-049) to decide
     * whether the top-ranked candidate is a confident automatic match or the
     * pool needs guided disambiguation, without loading every matching row.
     *
     * @param  array{brand_id?: int, family_id?: int, subfamily_id?: int, attributes?: array<int, array{key: string, min?: float, max?: float, value?: string}>}  $filters
     * @return Collection<int, SearchResult>
     */
    public function topCandidates(string $query, array $filters, int $limit): Collection
    {
        $exactMatchProductId = $this->findExactCodeMatchProductId($query);

        return $this->buildSearchQuery($query, $filters, $exactMatchProductId)
            ->limit($limit)
            ->get()
            ->map(fn (Product $product): SearchResult => $this->mapToResult($product, $exactMatchProductId));
    }

    /**
     * Builds the filtered, ranked product query shared by {@see search()},
     * {@see paginate()} and {@see topCandidates()}.
     *
     * @param  array{brand_id?: int, family_id?: int, subfamily_id?: int, attributes?: array<int, array{key: string, min?: float, max?: float, value?: string}>}  $filters
     * @return Builder<Product>
     */
    private function buildSearchQuery(string $query, array $filters, ?int $exactMatchProductId): Builder
    {
        $builder = $this->applyFilters(Product::query(), $filters);

        return $this->applyRanking($builder, $query, $exactMatchProductId, hasFilters: $filters !== []);
    }

    /**
     * Maps a ranked `Product` row into a {@see SearchResult}, reading back
     * the `vector_score` column selected by {@see applyRanking()} (`null` on
     * the non-pgsql fallback path, which never selects it) and flagging the
     * exact `codice_articolo` match independent of driver.
     */
    private function mapToResult(Product $product, ?int $exactMatchProductId): SearchResult
    {
        $vectorScore = $product->getAttribute('vector_score');

        return new SearchResult(
            product: $product,
            vectorScore: $vectorScore !== null ? (float) $vectorScore : null,
            isExactCodeMatch: $exactMatchProductId !== null && $product->id === $exactMatchProductId,
        );
    }

    /**
     * Rank the (already filtered) product query by the weighted fusion of
     * vector similarity and full-text relevance, forcing the exact code
     * match (when any) to the very top.
     *
     * On PostgreSQL this joins `product_embeddings` directly on
     * `product_embeddings.product_id = products.id` for the current
     * embedding model, and uses `products.search_vector`/`ts_rank` for the
     * FTS score and pgvector's `<=>` cosine distance operator for the vector
     * score. On any other driver (no tsvector/pgvector support) it falls
     * back to a plain `LIKE` match on product_type/description_clean,
     * without vector fusion.
     *
     * When `$query` is non-blank and no structured filter narrowed the pool
     * already, rows with neither a real FTS match nor a meaningful vector
     * match (both scores at or below `config('search.relevance.min_score')`)
     * are excluded — otherwise a query with no real match would rank (and
     * return) the entire catalog. The raw per-signal scores are used for
     * this check rather than the weighted `combined_score`, since a poor
     * vector match legitimately pulls that weighted sum negative even for a
     * row with a strong FTS match (or vice versa) — that's a ranking signal,
     * not a sign of irrelevance. The cutoff is skipped entirely when
     * structured filters are present (they already narrowed the pool
     * intentionally) or the query is blank. The exact code match is always
     * kept regardless.
     *
     * @param  Builder<Product>  $builder
     * @return Builder<Product>
     */
    private function applyRanking(Builder $builder, string $query, ?int $exactMatchProductId, bool $hasFilters): Builder
    {
        $isExactMatch = $exactMatchProductId !== null
            ? "CASE WHEN products.id = {$exactMatchProductId} THEN 1 ELSE 0 END"
            : 'CASE WHEN 1 = 0 THEN 1 ELSE 0 END';

        if (DB::connection()->getDriverName() !== 'pgsql') {
            $like = '%'.$query.'%';

            return $builder
                ->where(function (Builder $q) use ($like) {
                    $q->where('product_type', 'like', $like)
                        ->orWhere('description_clean', 'like', $like);
                })
                ->orderByRaw("{$isExactMatch} DESC")
                ->orderBy('product_type');
        }

        $model = config('services.embedding.model');
        $queryEmbedding = '['.implode(',', $this->embedQuery($query)).']';
        $vectorWeight = (float) config('search.weights.vector');
        $ftsWeight = (float) config('search.weights.fts');
        $ftsScoreExpression = "ts_rank(products.search_vector, plainto_tsquery('italian', ?))";
        $embeddingSubquery = 'select pe.embedding from product_embeddings pe '
            .'where pe.product_id = products.id and pe.model = ? '
            .'limit 1';
        $vectorScoreExpression = "COALESCE(1 - (({$embeddingSubquery}) <=> ?), 0)";

        $builder = $builder
            ->select('products.*')
            ->selectRaw("{$ftsScoreExpression} AS fts_score", [$query])
            ->selectRaw("{$vectorScoreExpression} AS vector_score", [$model, $queryEmbedding])
            ->selectRaw(
                "({$vectorWeight} * {$vectorScoreExpression}) + ({$ftsWeight} * {$ftsScoreExpression}) AS combined_score",
                [$model, $queryEmbedding, $query],
            );

        if (trim($query) !== '' && ! $hasFilters) {
            $minScore = (float) config('search.relevance.min_score');

            $builder->where(function (Builder $q) use ($ftsScoreExpression, $vectorScoreExpression, $query, $model, $queryEmbedding, $minScore, $exactMatchProductId) {
                $q->whereRaw("{$ftsScoreExpression} > ?", [$query, $minScore])
                    ->orWhereRaw("{$vectorScoreExpression} > ?", [$model, $queryEmbedding, $minScore]);

                if ($exactMatchProductId !== null) {
                    $q->orWhere('products.id', $exactMatchProductId);
                }
            });
        }

        return $builder
            ->orderByRaw("{$isExactMatch} DESC")
            ->orderByRaw('combined_score DESC');
    }

    /**
     * Apply optional structured filters (brand, family, subfamily, attribute
     * constraints) to the base product query, before ranking.
     *
     * @param  Builder<Product>  $builder
     * @param  array{brand_id?: int, family_id?: int, subfamily_id?: int, attributes?: array<int, array{key: string, min?: float, max?: float, value?: string}>}  $filters
     * @return Builder<Product>
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
                'attributes',
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
     * Resolve the id of the product whose `codice_articolo` matches the
     * query exactly (case-insensitive), if any. Used to force an exact code
     * match to the top of the results.
     */
    private function findExactCodeMatchProductId(string $query): ?int
    {
        $trimmed = trim($query);

        if ($trimmed === '') {
            return null;
        }

        return Product::query()
            ->whereRaw('LOWER(codice_articolo) = ?', [mb_strtolower($trimmed)])
            ->value('id');
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
