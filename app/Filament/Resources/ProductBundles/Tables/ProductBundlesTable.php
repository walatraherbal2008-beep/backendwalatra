<?php

namespace App\Filament\Resources\ProductBundles\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ProductBundlesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Nama bundle')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('code')
                    ->label('Kode bundle')
                    ->searchable(),
                TextColumn::make('price')
                    ->label('Harga')
                    ->numeric(decimalPlaces: 0, decimalSeparator: '.', thousandsSeparator: '')
                    ->prefix('Rp '),
                TextColumn::make('items_count')
                    ->label('Jumlah komponen')
                    ->counts('items'),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options([
                        'active' => 'Aktif',
                        'inactive' => 'Tidak aktif',
                    ]),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
