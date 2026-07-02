<?php

namespace App\Filament\Resources\ProductBases\RelationManagers;

use App\Filament\Resources\Products\ProductResource;
use Filament\Resources\RelationManagers\RelationManager;

class ProductsRelationManager extends RelationManager
{
    protected static string $relationship = 'products';

    protected static ?string $relatedResource = ProductResource::class;
}
