<?php

namespace App\Services\Enrichment;

use App\Models\Brand;
use App\Models\Product;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

/**
 * Resolves a product's brand deterministically from its raw description,
 * before falling back to AI-based enrichment: a trailing `-MARCA-` suffix
 * (confidence 95, brand_source `regex`) takes priority over an inline
 * dictionary alias match (confidence 80, brand_source `dictionary`).
 * Zero or 2+ distinct brands matched inline is treated as ambiguous and
 * leaves the product unresolved, but description_clean is still populated
 * with the trimmed source text so downstream resolvers (US-010+) can rely
 * on it being non-null.
 */
class BrandResolver
{
    /**
     * Lazily loaded and memoized across resolve() calls on the same
     * instance, so a future batch job can reuse one resolver without N+1.
     *
     * @var Collection<int, Brand>|null
     */
    private ?Collection $brands = null;

    public function __construct(
        private readonly EnrichmentProposalRecorder $recorder,
    ) {}

    /**
     * Attempt to resolve and persist the brand for the given product.
     * No-ops when the product already has a brand (idempotency guard).
     *
     * @return bool Whether a brand was resolved and assigned.
     */
    public function resolve(Product $product): bool
    {
        if ($product->brand_id !== null) {
            return false;
        }

        $text = trim((string) ($product->description_clean ?? $product->description_raw));

        if ($text === '') {
            return false;
        }

        $match = $this->matchSuffix($text, $this->brands()) ?? $this->matchInline($text, $this->brands());

        if ($match === null) {
            $product->fill(['description_clean' => $text])->save();

            return false;
        }

        [$brand, $cleaned, $source, $confidence] = $match;

        $product->fill([
            'brand_id' => $brand->id,
            'brand_source' => $source,
            'confidence' => $confidence,
            'description_clean' => $cleaned,
        ])->save();

        $this->recorder->record(
            product: $product,
            field: 'brand',
            origin: $source,
            status: 'applied',
            confidence: $confidence,
            valueId: $brand->id,
        );

        return true;
    }

    /**
     * @return Collection<int, Brand>
     */
    private function brands(): Collection
    {
        return $this->brands ??= Brand::all(['id', 'name', 'aliases']);
    }

    /**
     * Matches a trailing `-MARCA-` suffix against a brand name or alias.
     *
     * @param  Collection<int, Brand>  $brands
     * @return array{0: Brand, 1: string, 2: string, 3: int}|null
     */
    private function matchSuffix(string $text, Collection $brands): ?array
    {
        if (! preg_match('/-\s*([^-]+?)\s*-\s*$/u', $text, $matches, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        $token = Str::lower(trim($matches[1][0]));

        foreach ($brands as $brand) {
            if ($this->tokenMatchesBrand($token, $brand)) {
                $cleaned = $this->squeeze(substr($text, 0, $matches[0][1]));

                return [$brand, $cleaned, 'regex', 95];
            }
        }

        return null;
    }

    /**
     * Matches any brand name/alias appearing anywhere in the text as a whole
     * word. Zero or 2+ distinct brands matching is treated as no match.
     *
     * @param  Collection<int, Brand>  $brands
     * @return array{0: Brand, 1: string, 2: string, 3: int}|null
     */
    private function matchInline(string $text, Collection $brands): ?array
    {
        $hits = [];

        foreach ($brands as $brand) {
            foreach ($this->tokensFor($brand) as $token) {
                $pattern = '/(?<![\p{L}\p{N}])'.preg_quote($token, '/').'(?![\p{L}\p{N}])/iu';

                if (preg_match($pattern, $text, $matches, PREG_OFFSET_CAPTURE)) {
                    $hits[$brand->id] = [$brand, $matches[0][0], $matches[0][1]];

                    break;
                }
            }
        }

        if (count($hits) !== 1) {
            return null;
        }

        [$brand, $matchedText, $offset] = array_values($hits)[0];

        $cleaned = $this->squeeze(substr_replace($text, '', $offset, strlen($matchedText)));

        return [$brand, $cleaned, 'dictionary', 80];
    }

    private function tokenMatchesBrand(string $token, Brand $brand): bool
    {
        foreach ($this->tokensFor($brand) as $candidate) {
            if (Str::lower($candidate) === $token) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, string>
     */
    private function tokensFor(Brand $brand): array
    {
        return array_values(array_filter([$brand->name, ...($brand->aliases ?? [])]));
    }

    private function squeeze(string $text): string
    {
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text, " \t\n\r\0\x0B-");
    }
}
