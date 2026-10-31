<?php

namespace App\Filament\Resources\Customers\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class StatusHistoriesRelationManager extends RelationManager
{
    protected static string $relationship = 'statusHistories';

    protected static ?string $title = 'Riwayat Status Akun';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('previous_status')->label('Status sebelumnya')->placeholder('-')->badge(),
                TextColumn::make('new_status')->label('Status baru')->badge(),
                TextColumn::make('reason')->label('Alasan')->wrap()->limit(80),
                TextColumn::make('changedBy.name')->label('Diubah oleh')->placeholder('-'),
                TextColumn::make('created_at')->label('Waktu')->dateTime('d M Y H:i')->sortable(),
            ])
            ->headerActions([])
            ->recordActions([])
            ->toolbarActions([])
            ->defaultSort('created_at', 'desc');
    }
}
