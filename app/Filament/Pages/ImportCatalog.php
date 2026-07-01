<?php

namespace App\Filament\Pages;

use App\Exceptions\DuplicateImportException;
use App\Jobs\ImportXlsxJob;
use App\Jobs\PromoteStagingToProductsJob;
use App\Models\ImportBatch;
use App\Services\ImportBatchService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

/**
 * US-024: backoffice entry point for the catalog XLSX import, previously only
 * reachable via `catalog:import` on the command line (US-007/US-008). The
 * page uploads the file, starts the same job chain the console command uses
 * (`ImportBatchService::startImport()` -> `ImportXlsxJob` ->
 * `PromoteStagingToProductsJob`), and surfaces progress through a polling
 * table of `ImportBatch` records. No import business logic lives here.
 */
class ImportCatalog extends Page implements HasActions, HasSchemas, HasTable
{
    use InteractsWithActions;
    use InteractsWithSchemas;
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowUpTray;

    protected static ?string $navigationLabel = 'Importa Catalogo';

    protected static ?string $title = 'Importa Catalogo';

    protected string $view = 'filament.pages.import-catalog';

    /**
     * Prudential ceiling for the uploaded file, in kilobytes. No explicit
     * limit is given by the AC; this comfortably covers the real ~42k-row
     * TeamSystem export used across US-007/US-008.
     */
    private const MAX_UPLOAD_KB = 20 * 1024;

    protected function getHeaderActions(): array
    {
        return [
            $this->uploadAction(),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(ImportBatch::query()->orderByDesc('created_at'))
            ->poll('5s')
            ->columns([
                TextColumn::make('filename')
                    ->label('File'),
                TextColumn::make('status')
                    ->label('Stato')
                    ->badge()
                    ->color(fn (ImportBatch $record): string => match ($record->status->value) {
                        'completed' => 'success',
                        'failed' => 'danger',
                        'uploaded' => 'gray',
                        default => 'warning',
                    }),
                TextColumn::make('total_rows')
                    ->label('Righe totali')
                    ->numeric(),
                TextColumn::make('processed_rows')
                    ->label('Elaborate')
                    ->numeric(),
                TextColumn::make('rows_new')
                    ->label('Nuove')
                    ->numeric(),
                TextColumn::make('rows_updated')
                    ->label('Aggiornate')
                    ->numeric(),
                TextColumn::make('skipped_rows')
                    ->label('Saltate')
                    ->numeric(),
                TextColumn::make('finished_at')
                    ->label('Completato il')
                    ->dateTime(),
            ]);
    }

    /**
     * AC1/AC4: uploads an XLSX file and starts the same import chain used by
     * `catalog:import`. Errors from `ImportBatchService::startImport()` are
     * caught and surfaced as notifications instead of propagating as
     * unhandled exceptions to the request.
     */
    private function uploadAction(): Action
    {
        return Action::make('upload')
            ->label('Carica file XLSX')
            ->icon(Heroicon::OutlinedArrowUpTray)
            ->schema([
                FileUpload::make('file')
                    ->label('File XLSX')
                    ->acceptedFileTypes(['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])
                    ->disk('local')
                    ->directory('imports')
                    ->preserveFilenames(false)
                    ->maxSize(self::MAX_UPLOAD_KB)
                    ->required(),
            ])
            ->action(function (array $data, ImportBatchService $service): void {
                $path = Storage::disk('local')->path($data['file']);

                try {
                    $batch = $service->startImport($path);
                } catch (DuplicateImportException $e) {
                    Notification::make()
                        ->title('File già importato')
                        ->body($e->getMessage())
                        ->warning()
                        ->send();

                    return;
                } catch (InvalidArgumentException $e) {
                    Notification::make()
                        ->title('File non valido')
                        ->body($e->getMessage())
                        ->danger()
                        ->send();

                    return;
                }

                Bus::chain([
                    new ImportXlsxJob($batch, $path),
                    new PromoteStagingToProductsJob($batch),
                ])->dispatch();

                Notification::make()
                    ->title('Import avviato')
                    ->body("Batch #{$batch->id} ({$batch->filename}) in coda.")
                    ->success()
                    ->send();
            });
    }
}
