<?php

namespace App\Filament\Resources\Customers\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class OrdersRelationManager extends RelationManager
{
    protected static string $relationship = 'orders';

    protected static ?string $title = 'Riwayat Pesanan';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('order_number')->label('Nomor Pesanan')->searchable(),
                TextColumn::make('status')->label('Status')->badge(),
                TextColumn::make('total')->label('Total')->money('IDR'),
                TextColumn::make('placed_at')->label('Dibuat')->dateTime('d M Y H:i')->sortable(),
            ])
            ->headerActions([])
            ->recordActions([])
            ->toolbarActions([])
            ->defaultSort('created_at', 'desc');
    }
}
