<?php

namespace App\Filament\Resources\Subfamilies;

use App\Filament\Resources\Subfamilies\Pages\CreateSubfamily;
use App\Filament\Resources\Subfamilies\Pages\EditSubfamily;
use App\Filament\Resources\Subfamilies\Pages\ListSubfamilies;
use App\Filament\Resources\Subfamilies\Schemas\SubfamilyForm;
use App\Filament\Resources\Subfamilies\Tables\SubfamiliesTable;
use App\Models\Subfamily;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class SubfamilyResource extends Resource
{
    protected static ?string $model = Subfamily::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|UnitEnum|null $navigationGroup = 'Anagrafiche';

    public static function form(Schema $schema): Schema
    {
        return SubfamilyForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SubfamiliesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSubfamilies::route('/'),
            'create' => CreateSubfamily::route('/create'),
            'edit' => EditSubfamily::route('/{record}/edit'),
        ];
    }
}
