<?php

namespace App\Filament\Resources\StoreAdmins;

use App\Filament\Resources\StoreAdmins\Pages\ManageStoreAdmins;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class StoreAdminResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static ?string $recordTitleAttribute = 'name';

    protected static string|UnitEnum|null $navigationGroup = 'Manajemen Marketplace';

    protected static ?int $navigationSort = 1;

    protected static ?string $navigationLabel = 'Akun Seller';

    protected static ?string $modelLabel = 'akun seller';

    protected static ?string $pluralModelLabel = 'akun seller';

    public static function canAccess(): bool
    {
        return auth()->user()?->hasRole('super_admin') ?? false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->role('store_admin');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('Nama seller')
                    ->required()
                    ->maxLength(255),
                TextInput::make('email')
                    ->label('Email')
                    ->email()
                    ->required()
                    ->unique(ignoreRecord: true),
                TextInput::make('phone')
                    ->label('Nomor WhatsApp')
                    ->tel()
                    ->required()
                    ->unique(ignoreRecord: true),
                TextInput::make('password')
                    ->label('Password')
                    ->password()
                    ->revealable()
                    ->minLength(6)
                    ->required(fn (string $operation): bool => $operation === 'create')
                    ->dehydrated(fn (?string $state): bool => filled($state)),
                Select::make('status')
                    ->label('Status akun')
                    ->options([
                        'active' => 'Aktif',
                        'inactive' => 'Tidak aktif',
                    ])
                    ->default('active')
                    ->required(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name')
                    ->label('Nama seller')
                    ->searchable(),
                TextColumn::make('email')
                    ->searchable(),
                TextColumn::make('phone')
                    ->label('WhatsApp')
                    ->searchable(),
                TextColumn::make('store.name')
                    ->label('Toko')
                    ->placeholder('Belum memiliki toko')
                    ->searchable(),
                TextColumn::make('email_verified_at')
                    ->label('Verifikasi email')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => $state ? 'Terverifikasi' : 'Belum terverifikasi')
                    ->color(fn ($state): string => $state ? 'success' : 'warning'),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => $state === 'active' ? 'success' : 'danger'),
            ])
            ->filters([])
            ->recordActions([
                EditAction::make(),
                Action::make('verifyEmail')
                    ->label('Verifikasi email')
                    ->icon(Heroicon::OutlinedCheckCircle)
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (User $record): bool => $record->email_verified_at === null)
                    ->action(function (User $record): void {
                        $record->forceFill(['email_verified_at' => now()])->save();

                        Notification::make()
                            ->title('Email seller berhasil diverifikasi')
                            ->success()
                            ->send();
                    }),
            ])
            ->toolbarActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageStoreAdmins::route('/'),
        ];
    }
}
