<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Services\Search\MatchOutcome;
use App\Services\Search\NaturalLanguageSearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PDOException;
use Tests\TestCase;

/**
 * US-049 TASK-07 — end-to-end confidence outcome at the orchestration level
 * ({@see NaturalLanguageSearchService::matchOutcome()}): a query with one
 * candidate clearly ahead resolves to AutoMatch, two variants sharing an
 * identical embedding resolve to Disambiguation with both in the candidate
 * list, an exact `codice_articolo` match is always AutoMatch even with
 * close vector scores, and a query with no matches resolves to NoResults.
 *
 * Natural-language AI parsing is disabled here (`search.natural_language.
 * enabled = false`): matchOutcome()'s tri-state resolution is the thing
 * under test, not the AI query parse (already covered by
 * NaturalLanguageSearchServiceTest/QueryParser tests), so the query is used
 * verbatim (ParsedSearchQuery::wholeTextFallback()) with no AI call.
 *
 * Postgres-only (pgvector + tsvector), same defensive skip as
 * SearchServiceFusionRankingTest — embeddings are inserted manually the
 * same way.
 */
class NaturalLanguageSearchServiceMatchOutcomeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Match outcome assertions require the pgsql driver.');
        }

        try {
            DB::connection()->getPdo();
        } catch (PDOException $e) {
            $this->markTestSkipped('PostgreSQL is not reachable: '.$e->getMessage());
        }

        Artisan::call('migrate', ['--force' => true]);

        Queue::fake();

        config()->set('services.embedding', [
            'base_url' => 'https://embedding.test',
            'model' => 'test-model',
            'api_key' => null,
            'dimensions' => 1024,
            'timeout' => 120,
            'retry_times' => 2,
            'retry_delay_ms' => 1,
        ]);

        config()->set('search.natural_language.enabled', false);
        config()->set('search.confidence.auto_match_margin_threshold', 0.15);
        config()->set('search.confidence.max_candidates', 5);
    }

    /**
     * @param  array<int, float>  $head
     * @return array<int, float>
     */
    private function pad(array $head): array
    {
        return array_merge($head, array_fill(0, 1024 - count($head), 0));
    }

    /**
     * @param  array<int, float>  $vector
     */
    private function insertEmbedding(Product $product, array $vector): void
    {
        DB::table('product_embeddings')->insert([
            'product_id' => $product->id,
            'content' => 'seed',
            'content_hash' => hash('sha256', 'seed'),
            'model' => 'test-model',
            'dimensions' => 1024,
            'embedding' => '['.implode(',', $vector).']',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_query_with_one_candidate_clearly_ahead_is_auto_match(): void
    {
        $best = Product::factory()->create([
            'product_type' => 'Scaldabagno a pompa di calore',
            'description_clean' => 'Scaldabagno a pompa di calore risparmio energetico',
        ]);
        $this->insertEmbedding($best, $this->pad([1, 0, 0]));

        // Not a mere scalar multiple of the query embedding (which would
        // keep the cosine similarity at 1): an orthogonal component pulls
        // this one's vector_score meaningfully below the top candidate's.
        $farOff = Product::factory()->create([
            'product_type' => 'Scaldabagno generico',
            'description_clean' => 'Scaldabagno generico per uso domestico',
        ]);
        $this->insertEmbedding($farOff, $this->pad([0.5, 0.5, 0]));

        Http::fake([
            '*' => Http::response(['embedding' => $this->pad([1, 0, 0])]),
        ]);

        $outcome = app(NaturalLanguageSearchService::class)->matchOutcome('scaldabagno pompa calore');

        $this->assertSame(MatchOutcome::AutoMatch, $outcome->outcome);
        $this->assertNotNull($outcome->margin);
        $this->assertSame($best->id, $outcome->candidates->first()->product->id);
    }

    public function test_two_variants_with_identical_embedding_are_disambiguation_with_both_candidates(): void
    {
        $variantA = Product::factory()->create([
            'codice_articolo' => 'VAR-A',
            'product_type' => 'Scaldabagno a pompa di calore',
            'description_clean' => 'Scaldabagno a pompa di calore variante A',
        ]);
        $this->insertEmbedding($variantA, $this->pad([1, 0, 0]));

        $variantB = Product::factory()->create([
            'codice_articolo' => 'VAR-B',
            'product_type' => 'Scaldabagno a pompa di calore',
            'description_clean' => 'Scaldabagno a pompa di calore variante B',
        ]);
        $this->insertEmbedding($variantB, $this->pad([1, 0, 0]));

        Http::fake([
            '*' => Http::response(['embedding' => $this->pad([1, 0, 0])]),
        ]);

        $outcome = app(NaturalLanguageSearchService::class)->matchOutcome('scaldabagno pompa calore');

        $this->assertSame(MatchOutcome::Disambiguation, $outcome->outcome);
        $this->assertSame(0.0, $outcome->margin);

        $candidateIds = $outcome->candidates->map(fn ($result) => $result->product->id);
        $this->assertTrue($candidateIds->contains($variantA->id));
        $this->assertTrue($candidateIds->contains($variantB->id));
    }

    public function test_exact_code_match_query_is_auto_match_even_with_close_vector_scores(): void
    {
        $exactMatch = Product::factory()->create([
            'product_type' => 'Valvola a sfera in ottone',
            'description_clean' => 'Valvola a sfera in ottone per impianti idraulici',
            'codice_articolo' => 'ABC-12345',
        ]);
        $this->insertEmbedding($exactMatch, $this->pad([0.5, 0, 0]));

        $closeRival = Product::factory()->create([
            'product_type' => 'Valvola a sfera in acciaio',
            'description_clean' => 'Valvola a sfera in acciaio per impianti idraulici',
        ]);
        $this->insertEmbedding($closeRival, $this->pad([0.49, 0, 0]));

        Http::fake([
            '*' => Http::response(['embedding' => $this->pad([1, 0, 0])]),
        ]);

        $outcome = app(NaturalLanguageSearchService::class)->matchOutcome('ABC-12345');

        $this->assertSame(MatchOutcome::AutoMatch, $outcome->outcome);
        $this->assertSame(1.0, $outcome->margin);
        $this->assertSame($exactMatch->id, $outcome->candidates->first()->product->id);
    }

    public function test_query_with_no_matches_is_no_results(): void
    {
        Product::factory()->create([
            'product_type' => 'Valvola a sfera in ottone',
            'description_clean' => 'Valvola a sfera in ottone per impianti idraulici',
        ]);

        Http::fake([
            '*' => Http::response(['embedding' => $this->pad([-1, 0, 0])]),
        ]);

        $outcome = app(NaturalLanguageSearchService::class)->matchOutcome('inesistente xyzzy nessuncorrispondenza');

        $this->assertSame(MatchOutcome::NoResults, $outcome->outcome);
        $this->assertNull($outcome->margin);
        $this->assertTrue($outcome->candidates->isEmpty());
    }
}
