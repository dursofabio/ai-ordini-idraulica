<?php

namespace App\Filament\Resources\ProductBases;

use App\Filament\Resources\ProductBases\Pages\EditProductBase;
use App\Filament\Resources\ProductBases\Pages\ListProductBases;
use App\Filament\Resources\ProductBases\Schemas\ProductBaseForm;
use App\Filament\Resources\ProductBases\Tables\ProductBasesTable;
use App\Models\ProductBase;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class ProductBaseResource extends Resource
{
    protected static ?string $model = ProductBase::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    public static function form(Schema $schema): Schema
    {
        return ProductBaseForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ProductBasesTable::configure($table);
    }

    /**
     * Product bases are populated by the import/grouping pipeline, not
     * created manually in the backoffice.
     */
    public static function canCreate(): bool
    {
        return false;
    }

    /**
     * Product bases must not be deletable from the backoffice since
     * products and embeddings depend on them.
     */
    public static function canDeleteAny(): bool
    {
        return false;
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
            'index' => ListProductBases::route('/'),
            'edit' => EditProductBase::route('/{record}/edit'),
        ];
    }
}
