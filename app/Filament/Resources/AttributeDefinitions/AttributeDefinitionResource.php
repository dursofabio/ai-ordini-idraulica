<?php

namespace App\Filament\Resources\AttributeDefinitions;

use App\Filament\Resources\AttributeDefinitions\Pages\CreateAttributeDefinition;
use App\Filament\Resources\AttributeDefinitions\Pages\EditAttributeDefinition;
use App\Filament\Resources\AttributeDefinitions\Pages\ListAttributeDefinitions;
use App\Filament\Resources\AttributeDefinitions\Schemas\AttributeDefinitionForm;
use App\Filament\Resources\AttributeDefinitions\Tables\AttributeDefinitionsTable;
use App\Models\AttributeDefinition;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class AttributeDefinitionResource extends Resource
{
    protected static ?string $model = AttributeDefinition::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    protected static string|UnitEnum|null $navigationGroup = 'Anagrafiche';

    protected static ?string $navigationLabel = 'Chiavi Attributo';

    protected static ?string $modelLabel = 'chiave attributo';

    protected static ?string $pluralModelLabel = 'chiavi attributo';

    public static function form(Schema $schema): Schema
    {
        return AttributeDefinitionForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AttributeDefinitionsTable::configure($table);
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
            'index' => ListAttributeDefinitions::route('/'),
            'create' => CreateAttributeDefinition::route('/create'),
            'edit' => EditAttributeDefinition::route('/{record}/edit'),
        ];
    }
}
