<?php

namespace Tests\Feature;

use App\Models\EnrichmentLog;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\RequiresDatabase;
use Tests\TestCase;

/**
 * `catalog:reset-failed-enrichments` acceptance criteria:
 *  - A `needs_review` product whose only `ai_classification` log is a hard
 *    failure (`output` carries an "error" key, as written by
 *    `ClassifyProductsBatchJob::markNeedsReview()`) is reset to `pending`
 *    and its failed-attempt log row is deleted.
 *  - A `needs_review` product that has at least one valid (non-error)
 *    `ai_classification` log is left untouched — it is a genuine
 *    low-confidence result awaiting human review, not a failure — even if it
 *    also has an unrelated failed attempt on record.
 *  - A `needs_review` product with no `ai_classification` log at all is left
 *    untouched.
 *  - Logs from other steps (e.g. `deterministic`) are never deleted.
 *  - Queue workers are restarted before any product is touched.
 */
class ResetFailedEnrichmentsCommandTest extends TestCase
{
    use RefreshDatabase;
    use RequiresDatabase;

    public function test_resets_product_whose_only_classification_attempt_failed(): void
    {
        $product = Product::factory()->create(['enrichment_status' => 'needs_review']);

        $failedLog = EnrichmentLog::factory()->create([
            'product_id' => $product->id,
            'step' => 'ai_classification',
            'output' => ['error' => 'Risposta AI non valida dopo un retry.'],
            'confidence' => null,
        ]);

        $this->artisan('catalog:reset-failed-enrichments')
            ->assertSuccessful()
            ->expectsOutputToContain('Prodotti riportati a "pending": 1')
            ->expectsOutputToContain('Log di arricchimento falliti eliminati: 1');

        $product->refresh();

        $this->assertSame('pending', $product->enrichment_status);
        $this->assertDatabaseMissing('enrichment_logs', ['id' => $failedLog->id]);
    }

    public function test_leaves_product_with_a_valid_low_confidence_result_untouched(): void
    {
        $product = Product::factory()->create(['enrichment_status' => 'needs_review']);

        $validLog = EnrichmentLog::factory()->create([
            'product_id' => $product->id,
            'step' => 'ai_classification',
            'output' => ['brand' => 'Wavin', 'confidence' => 40],
            'confidence' => 40,
        ]);

        $failedLog = EnrichmentLog::factory()->create([
            'product_id' => $product->id,
            'step' => 'ai_classification',
            'output' => ['error' => 'Risposta AI non valida dopo un retry.'],
            'confidence' => null,
        ]);

        $this->artisan('catalog:reset-failed-enrichments')
            ->assertSuccessful()
            ->expectsOutputToContain('Nessun prodotto "needs_review" causato da un fallimento di classificazione AI.');

        $product->refresh();

        $this->assertSame('needs_review', $product->enrichment_status);
        $this->assertDatabaseHas('enrichment_logs', ['id' => $validLog->id]);
        $this->assertDatabaseHas('enrichment_logs', ['id' => $failedLog->id]);
    }

    public function test_leaves_product_with_no_classification_log_untouched(): void
    {
        $product = Product::factory()->create(['enrichment_status' => 'needs_review']);

        $this->artisan('catalog:reset-failed-enrichments')
            ->assertSuccessful()
            ->expectsOutputToContain('Nessun prodotto "needs_review" causato da un fallimento di classificazione AI.');

        $product->refresh();

        $this->assertSame('needs_review', $product->enrichment_status);
    }

    public function test_never_deletes_logs_from_other_steps(): void
    {
        $product = Product::factory()->create(['enrichment_status' => 'needs_review']);

        $deterministicLog = EnrichmentLog::factory()->create([
            'product_id' => $product->id,
            'step' => 'deterministic',
            'output' => ['error' => 'irrelevant for this step'],
            'confidence' => null,
        ]);

        EnrichmentLog::factory()->create([
            'product_id' => $product->id,
            'step' => 'ai_classification',
            'output' => ['error' => 'Risposta AI non valida dopo un retry.'],
            'confidence' => null,
        ]);

        $this->artisan('catalog:reset-failed-enrichments')->assertSuccessful();

        $this->assertDatabaseHas('enrichment_logs', ['id' => $deterministicLog->id]);
    }

    public function test_leaves_non_needs_review_products_untouched(): void
    {
        $pending = Product::factory()->create(['enrichment_status' => 'pending']);
        $enriched = Product::factory()->create(['enrichment_status' => 'enriched']);

        $this->artisan('catalog:reset-failed-enrichments')
            ->assertSuccessful()
            ->expectsOutputToContain('Nessun prodotto "needs_review" causato da un fallimento di classificazione AI.');

        $this->assertSame('pending', $pending->fresh()->enrichment_status);
        $this->assertSame('enriched', $enriched->fresh()->enrichment_status);
    }
}
