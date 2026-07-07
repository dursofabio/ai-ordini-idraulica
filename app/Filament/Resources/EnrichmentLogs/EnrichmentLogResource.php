<?php

namespace App\Filament\Resources\EnrichmentLogs;

use App\Filament\Resources\EnrichmentLogs\Pages\ListEnrichmentLogs;
use App\Filament\Resources\EnrichmentLogs\Pages\ViewEnrichmentLog;
use App\Filament\Resources\EnrichmentLogs\Schemas\EnrichmentLogInfolist;
use App\Filament\Resources\EnrichmentLogs\Tables\EnrichmentLogsTable;
use App\Jobs\ClassifyProductsBatchJob;
use App\Jobs\DeepEnrichProductJob;
use App\Models\EnrichmentLog;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * Read-only backoffice section listing every AI request/response across all
 * products, so an admin can inspect the full prompt sent to and the raw
 * response received from the AI provider without querying the database or
 * tailing application logs. No create/edit/delete pages are registered on
 * purpose: `enrichment_logs` rows are written exclusively by the enrichment
 * pipeline ({@see ClassifyProductsBatchJob}, {@see DeepEnrichProductJob}).
 */
class EnrichmentLogResource extends Resource
{
    protected static ?string $model = EnrichmentLog::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSparkles;

    protected static string|UnitEnum|null $navigationGroup = 'AI';

    protected static ?string $navigationLabel = 'Storico chiamate AI';

    protected static ?string $modelLabel = 'chiamata AI';

    protected static ?string $pluralModelLabel = 'chiamate AI';

    public static function table(Table $table): Table
    {
        return EnrichmentLogsTable::configure($table);
    }

    public static function infolist(Schema $schema): Schema
    {
        return EnrichmentLogInfolist::configure($schema);
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
            'index' => ListEnrichmentLogs::route('/'),
            'view' => ViewEnrichmentLog::route('/{record}'),
        ];
    }
}
