<?php

namespace App\Filament\Resources\Subfamilies\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class SubfamilyForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('Nome')
                    ->required(),
                TextInput::make('slug')
                    ->label('Slug')
                    ->required(),
                TagsInput::make('aliases')
                    ->label('Alias'),
                Select::make('family_id')
                    ->label('Famiglia')
                    ->relationship('family', 'name')
                    ->searchable()
                    ->preload()
                    ->nullable(),
            ]);
    }
}
