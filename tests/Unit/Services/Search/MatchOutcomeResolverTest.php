<?php

namespace Tests\Unit\Services\Search;

use App\Models\Product;
use App\Services\Search\MatchOutcome;
use App\Services\Search\MatchOutcomeResolver;
use App\Services\Search\SearchResult;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * US-049 TASK-05 — full precedence matrix of {@see MatchOutcomeResolver},
 * exercised purely against in-memory {@see SearchResult} fixtures (no DB):
 * exact code match always wins, then single-candidate, then a non-positive
 * or missing top vector score is treated cautiously, then an exact tie
 * between the top two is always disambiguation, and only then does the
 * configured margin threshold decide.
 */
class MatchOutcomeResolverTest extends TestCase
{
    private function candidate(?float $vectorScore, bool $isExactCodeMatch = false): SearchResult
    {
        return new SearchResult(
            product: Product::factory()->make(),
            vectorScore: $vectorScore,
            isExactCodeMatch: $isExactCodeMatch,
        );
    }

    /**
     * @param  array<int, SearchResult>  $candidates
     * @return Collection<int, SearchResult>
     */
    private function candidates(array $candidates): Collection
    {
        return collect($candidates);
    }

    public function test_empty_collection_is_no_results(): void
    {
        $outcome = (new MatchOutcomeResolver)->resolve($this->candidates([]));

        $this->assertSame(MatchOutcome::NoResults, $outcome->outcome);
        $this->assertNull($outcome->margin);
        $this->assertTrue($outcome->candidates->isEmpty());
    }

    public function test_single_candidate_is_always_auto_match(): void
    {
        $outcome = (new MatchOutcomeResolver)->resolve($this->candidates([
            $this->candidate(0.4),
        ]));

        $this->assertSame(MatchOutcome::AutoMatch, $outcome->outcome);
        $this->assertSame(1.0, $outcome->margin);
    }

    public function test_exact_code_match_is_always_auto_match_even_with_low_margin(): void
    {
        config()->set('search.confidence.auto_match_margin_threshold', 0.15);

        $outcome = (new MatchOutcomeResolver)->resolve($this->candidates([
            $this->candidate(0.5, isExactCodeMatch: true),
            $this->candidate(0.49),
        ]));

        $this->assertSame(MatchOutcome::AutoMatch, $outcome->outcome);
        $this->assertSame(1.0, $outcome->margin);
    }

    public function test_exact_code_match_is_always_auto_match_even_with_null_vector_score(): void
    {
        $outcome = (new MatchOutcomeResolver)->resolve($this->candidates([
            $this->candidate(null, isExactCodeMatch: true),
            $this->candidate(0.9),
        ]));

        $this->assertSame(MatchOutcome::AutoMatch, $outcome->outcome);
        $this->assertSame(1.0, $outcome->margin);
    }

    public function test_null_vector_score_on_top_two_is_cautious_disambiguation(): void
    {
        $outcome = (new MatchOutcomeResolver)->resolve($this->candidates([
            $this->candidate(null),
            $this->candidate(null),
        ]));

        $this->assertSame(MatchOutcome::Disambiguation, $outcome->outcome);
        $this->assertNull($outcome->margin);
    }

    public function test_non_positive_top_vector_score_is_cautious_disambiguation(): void
    {
        $outcome = (new MatchOutcomeResolver)->resolve($this->candidates([
            $this->candidate(0.0),
            $this->candidate(-0.2),
        ]));

        $this->assertSame(MatchOutcome::Disambiguation, $outcome->outcome);
        $this->assertNull($outcome->margin);
    }

    public function test_exact_score_parity_is_always_disambiguation_even_with_zero_threshold(): void
    {
        config()->set('search.confidence.auto_match_margin_threshold', 0.0);

        $outcome = (new MatchOutcomeResolver)->resolve($this->candidates([
            $this->candidate(0.85),
            $this->candidate(0.85),
        ]));

        $this->assertSame(MatchOutcome::Disambiguation, $outcome->outcome);
        $this->assertSame(0.0, $outcome->margin);
    }

    public function test_margin_at_or_above_threshold_is_auto_match(): void
    {
        config()->set('search.confidence.auto_match_margin_threshold', 0.2);

        // margin = (0.8 - 0.6) / 0.8 = 0.25 >= 0.2
        $outcome = (new MatchOutcomeResolver)->resolve($this->candidates([
            $this->candidate(0.8),
            $this->candidate(0.6),
        ]));

        $this->assertSame(MatchOutcome::AutoMatch, $outcome->outcome);
        $this->assertEqualsWithDelta(0.25, $outcome->margin, 0.0001);
    }

    public function test_margin_below_threshold_is_disambiguation(): void
    {
        config()->set('search.confidence.auto_match_margin_threshold', 0.3);

        // margin = (0.8 - 0.7) / 0.8 = 0.125 < 0.3
        $outcome = (new MatchOutcomeResolver)->resolve($this->candidates([
            $this->candidate(0.8),
            $this->candidate(0.7),
        ]));

        $this->assertSame(MatchOutcome::Disambiguation, $outcome->outcome);
        $this->assertEqualsWithDelta(0.125, $outcome->margin, 0.0001);
    }
}
