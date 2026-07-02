<?php

namespace App\Filament\Resources\ImportBatches;

use App\Filament\Resources\ImportBatches\Pages\ListImportBatches;
use App\Filament\Resources\ImportBatches\Pages\ViewImportBatch;
use App\Filament\Resources\ImportBatches\Schemas\ImportBatchInfolist;
use App\Filament\Resources\ImportBatches\Tables\ImportBatchesTable;
use App\Models\ImportBatch;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * US-029: read-only backoffice section listing import batches and their
 * outcome, so an admin can inspect an import without the database or CLI
 * logs. No create/edit/delete pages are registered on purpose (AC5).
 */
class ImportBatchResource extends Resource
{
    protected static ?string $model = ImportBatch::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQueueList;

    protected static string|UnitEnum|null $navigationGroup = 'Import';

    protected static ?string $navigationLabel = 'Storico Import';

    public static function table(Table $table): Table
    {
        return ImportBatchesTable::configure($table);
    }

    public static function infolist(Schema $schema): Schema
    {
        return ImportBatchInfolist::configure($schema);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListImportBatches::route('/'),
            'view' => ViewImportBatch::route('/{record}'),
        ];
    }
}
