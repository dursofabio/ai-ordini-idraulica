<?php

namespace App\Filament\Resources\Products\Actions;

use App\Filament\Resources\Products\Pages\ViewProduct;
use App\Filament\Resources\Products\Tables\ProductsTable;
use App\Jobs\ClassifyProductsBatchJob;
use App\Jobs\DeepEnrichProductJob;
use App\Jobs\GenerateProductEmbeddingJob;
use App\Jobs\RunDeterministicEnrichmentJob;
use App\Models\Product;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

/**
 * Manual per-product reprocessing actions (US-031), shared between the
 * products table row actions ({@see ProductsTable})
 * and the product view page header actions
 * ({@see ViewProduct}), so both
 * surfaces expose exactly the same behavior (AC4).
 */
class ProductEnrichmentActions
{
    /**
     * Re-runs the deterministic (Step A) resolvers for a single product via
     * {@see RunDeterministicEnrichmentJob}, which resets `enrichment_status`
     * to `pending` before invoking the existing pipeline.
     */
    public static function relaunchDeterministicEnrichment(): Action
    {
        return Action::make('relaunchDeterministicEnrichment')
            ->label('Rilancia arricchimento deterministico')
            ->icon(Heroicon::OutlinedArrowPath)
            ->action(function (Product $record): void {
                RunDeterministicEnrichmentJob::dispatch($record->id);

                Notification::make()
                    ->title('Arricchimento deterministico accodato')
                    ->success()
                    ->send();
            });
    }

    /**
     * Re-queues AI classification for a single product regardless of its
     * current `enrichment_status` (AC2): {@see ClassifyProductsBatchJob} only
     * picks up `pending` products, so the action resets the status before
     * dispatching it. Disabled (and defensively guarded in `action()`, AC5)
     * when the product has no description to classify.
     */
    public static function relaunchAiClassification(): Action
    {
        return Action::make('relaunchAiClassification')
            ->label('Rilancia classificazione AI')
            ->icon(Heroicon::OutlinedSparkles)
            ->disabled(fn (Product $record): bool => ! self::hasDescription($record))
            ->action(function (Product $record): void {
                if (! self::hasDescription($record)) {
                    Notification::make()
                        ->title('Impossibile classificare: descrizione mancante')
                        ->danger()
                        ->send();

                    return;
                }

                $record->update(['enrichment_status' => 'pending']);

                ClassifyProductsBatchJob::dispatch([$record->id]);

                Notification::make()
                    ->title('Classificazione AI accodata')
                    ->success()
                    ->send();
            });
    }

    /**
     * Queues a deep AI enrichment for a single product via
     * {@see DeepEnrichProductJob} (US-051): a markdown extended description
     * plus a full technical fact sheet, recorded as `pending` proposals in
     * the existing review queue rather than written directly to the product.
     * Disabled (and defensively guarded in `action()`) when the product has
     * no description to enrich from, mirroring
     * {@see self::relaunchAiClassification()}.
     */
    public static function deepEnrichWithAi(): Action
    {
        return Action::make('deepEnrichWithAi')
            ->label('Arricchisci con AI')
            ->icon(Heroicon::OutlinedSparkles)
            ->disabled(fn (Product $record): bool => ! self::hasDescription($record))
            ->action(function (Product $record): void {
                if (! self::hasDescription($record)) {
                    Notification::make()
                        ->title('Impossibile arricchire: descrizione mancante')
                        ->danger()
                        ->send();

                    return;
                }

                DeepEnrichProductJob::dispatch($record->id);

                Notification::make()
                    ->title('Arricchimento AI accodato')
                    ->success()
                    ->send();
            });
    }

    /**
     * Regenerates this product's own embedding via
     * {@see GenerateProductEmbeddingJob} (US-046), which overwrites any
     * existing embedding for that (product_id, model) pair. Since embedding
     * content is derived from the product's own product_type/brand (falling
     * back to description_clean), this no longer depends on the product
     * being linked to a product-base.
     */
    public static function regenerateEmbedding(): Action
    {
        return Action::make('regenerateEmbedding')
            ->label('Rigenera embedding')
            ->icon(Heroicon::OutlinedCpuChip)
            ->action(function (Product $record): void {
                GenerateProductEmbeddingJob::dispatch($record->id);

                Notification::make()
                    ->title('Rigenerazione embedding accodata')
                    ->success()
                    ->send();
            });
    }

    private static function hasDescription(Product $record): bool
    {
        return trim((string) ($record->description_clean ?? $record->description_raw)) !== '';
    }
}
