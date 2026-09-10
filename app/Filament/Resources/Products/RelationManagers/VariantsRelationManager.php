<?php

namespace App\Filament\Resources\Products\RelationManagers;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class VariantsRelationManager extends RelationManager
{
    protected static string $relationship = 'variants';

    protected static ?string $title = 'Varian Produk';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('Nama varian')
                    ->required()
                    ->maxLength(255),
                TextInput::make('sku')
                    ->label('SKU otomatis')
                    ->disabled()
                    ->dehydrated(false)
                    ->helperText('SKU dibuat otomatis saat varian disimpan.'),
                TextInput::make('price')
                    ->label('Harga dasar')
                    ->numeric()
                    ->prefix('Rp')
                    ->minValue(0)
                    ->required(),
                TextInput::make('weight_grams')
                    ->label('Berat (gram)')
                    ->numeric()
                    ->minValue(0)
                    ->required(),
                Select::make('status')
                    ->label('Status')
                    ->options([
                        'active' => 'Aktif',
                        'inactive' => 'Tidak aktif',
                    ])
                    ->default('active')
                    ->required(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name')
                    ->label('Nama varian')
                    ->searchable(),
                TextColumn::make('sku')
                    ->label('SKU')
                    ->searchable(),
                TextColumn::make('price')
                    ->label('Harga')
                    ->numeric(decimalPlaces: 0, decimalSeparator: '.', thousandsSeparator: '')
                    ->prefix('Rp '),
                TextColumn::make('weight_grams')
                    ->label('Berat')
                    ->suffix(' gram'),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge(),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Tambah Varian'),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()
                    ->label('Hapus')
                    ->requiresConfirmation(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
