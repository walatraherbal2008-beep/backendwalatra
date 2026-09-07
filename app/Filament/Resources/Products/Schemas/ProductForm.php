<?php

namespace App\Filament\Resources\Products\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class ProductForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('Nama produk')
                    ->required()
                    ->maxLength(255),
                TextInput::make('bpom_number')
                    ->label('Nomor BPOM')
                    ->maxLength(100),
                Select::make('status')
                    ->label('Status')
                    ->options([
                        'draft' => 'Draft',
                        'active' => 'Aktif',
                        'inactive' => 'Tidak aktif',
                    ])
                    ->default('draft')
                    ->required(),
            ]);
    }
}
