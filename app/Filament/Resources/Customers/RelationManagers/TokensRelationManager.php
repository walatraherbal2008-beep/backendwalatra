<?php

namespace App\Filament\Resources\Customers\RelationManagers;

use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class TokensRelationManager extends RelationManager
{
    protected static string $relationship = 'tokens';

    protected static ?string $title = 'Perangkat dan Token Aktif';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('Nama perangkat')->searchable(),
                TextColumn::make('last_used_at')->label('Terakhir digunakan')->dateTime('d M Y H:i')->placeholder('Belum digunakan'),
                TextColumn::make('created_at')->label('Dibuat')->dateTime('d M Y H:i'),
                TextColumn::make('expires_at')->label('Kadaluarsa')->dateTime('d M Y H:i')->placeholder('Tidak diatur'),
            ])
            ->headerActions([])
            ->recordActions([
                Action::make('revoke')
                    ->label('Cabut')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->action(function ($record): void {
                        $record->delete();
                        Notification::make()->title('Token perangkat berhasil dicabut.')->success()->send();
                    }),
            ])
            ->toolbarActions([])
            ->defaultSort('last_used_at', 'desc');
    }
}
