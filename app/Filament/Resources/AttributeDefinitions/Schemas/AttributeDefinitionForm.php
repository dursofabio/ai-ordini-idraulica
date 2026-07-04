<?php

namespace App\Filament\Resources\AttributeDefinitions\Schemas;

use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class AttributeDefinitionForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('key')
                    ->label('Chiave')
                    ->required()
                    ->unique(ignoreRecord: true)
                    ->maxLength(255),
                Select::make('data_type')
                    ->label('Tipo')
                    ->options([
                        'numeric' => 'Numerico',
                        'text' => 'Testo',
                    ])
                    ->required()
                    ->live(),
                TextInput::make('canonical_unit')
                    ->label('Unità canonica')
                    ->visible(fn (Get $get): bool => $get('data_type') === 'numeric'),
                KeyValue::make('accepted_units')
                    ->label('Unità accettate')
                    ->keyLabel('Unità')
                    ->valueLabel('Fattore di conversione')
                    ->visible(fn (Get $get): bool => $get('data_type') === 'numeric'),
                Textarea::make('description')
                    ->label('Descrizione')
                    ->columnSpanFull(),
            ]);
    }
}
