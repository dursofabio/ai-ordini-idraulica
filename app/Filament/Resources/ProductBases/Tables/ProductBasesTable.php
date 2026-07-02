<?php

namespace App\Filament\Resources\ProductBases\Tables;

use App\Jobs\GenerateProductBaseEmbeddingJob;
use App\Models\ProductBase;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ProductBasesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')
                    ->label('Titolo')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('brand.name')
                    ->label('Marca')
                    ->sortable(),
                TextColumn::make('family.name')
                    ->label('Famiglia')
                    ->sortable(),
                TextColumn::make('subfamily.name')
                    ->label('Sottofamiglia')
                    ->sortable(),
                TextColumn::make('products_count')
                    ->label('Prodotti')
                    ->counts('products')
                    ->badge()
                    ->sortable(),
                TextColumn::make('description_ai')
                    ->label('Descrizione AI')
                    ->limit(60)
                    ->sortable()
                    ->tooltip(function (TextColumn $column): ?string {
                        $state = $column->getState();

                        if (! is_string($state) || strlen($state) <= $column->getCharacterLimit()) {
                            return null;
                        }

                        return $state;
                    }),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                EditAction::make(),
                self::regenerateEmbeddingAction(),
            ]);
    }

    /**
     * Exposes a manual trigger for embedding regeneration independent of the
     * `ProductBase` observer, which only re-dispatches the job when
     * `description_ai` changes. Admins need this to force a refresh after an
     * embedding provider/model change, even when the description is unchanged.
     */
    protected static function regenerateEmbeddingAction(): Action
    {
        return Action::make('regenerateEmbedding')
            ->label('Rigenera embedding')
            ->icon(Heroicon::OutlinedArrowPath)
            ->action(function (ProductBase $record): void {
                GenerateProductBaseEmbeddingJob::dispatch($record->id);

                Notification::make()
                    ->title('Rigenerazione embedding avviata')
                    ->success()
                    ->send();
            });
    }
}
