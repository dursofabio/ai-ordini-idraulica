<?php

namespace App\Filament\Resources\ProductBases\Pages;

use App\Filament\Resources\ProductBases\ProductBaseResource;
use App\Jobs\GenerateProductBaseEmbeddingJob;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;

class EditProductBase extends EditRecord
{
    protected static string $resource = ProductBaseResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->regenerateEmbeddingAction(),
        ];
    }

    /**
     * Exposes a manual trigger for embedding regeneration independent of the
     * `ProductBase` observer, which only re-dispatches the job when
     * `description_ai` changes. Admins need this to force a refresh after an
     * embedding provider/model change, even when the description is unchanged.
     */
    protected function regenerateEmbeddingAction(): Action
    {
        return Action::make('regenerateEmbedding')
            ->label('Rigenera embedding')
            ->icon(Heroicon::OutlinedArrowPath)
            ->action(function (): void {
                GenerateProductBaseEmbeddingJob::dispatch($this->record->id);

                Notification::make()
                    ->title('Rigenerazione embedding avviata')
                    ->success()
                    ->send();
            });
    }
}
