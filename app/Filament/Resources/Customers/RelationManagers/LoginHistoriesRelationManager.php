<?php

namespace App\Filament\Resources\Customers\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class LoginHistoriesRelationManager extends RelationManager
{
    protected static string $relationship = 'loginHistories';

    protected static ?string $title = 'Riwayat Login';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('device_name')->label('Perangkat')->placeholder('-'),
                TextColumn::make('ip_address')->label('IP'),
                TextColumn::make('user_agent')->label('User agent')->limit(80)->wrap(),
                TextColumn::make('logged_in_at')->label('Waktu login')->dateTime('d M Y H:i')->sortable(),
            ])
            ->headerActions([])
            ->recordActions([])
            ->toolbarActions([])
            ->defaultSort('logged_in_at', 'desc');
    }
}
