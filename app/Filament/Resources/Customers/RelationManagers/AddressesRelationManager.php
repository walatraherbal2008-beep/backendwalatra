<?php

namespace App\Filament\Resources\Customers\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class AddressesRelationManager extends RelationManager
{
    protected static string $relationship = 'addresses';

    protected static ?string $title = 'Alamat Pengiriman';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('label')->label('Label')->searchable(),
                TextColumn::make('name')->label('Penerima')->searchable(),
                TextColumn::make('phone')->label('Nomor WhatsApp'),
                TextColumn::make('province')->label('Provinsi'),
                TextColumn::make('city')->label('Kota'),
                TextColumn::make('postal_code')->label('Kode Pos'),
                IconColumn::make('is_default')->label('Utama')->boolean(),
            ])
            ->headerActions([])
            ->recordActions([])
            ->toolbarActions([]);
    }
}
