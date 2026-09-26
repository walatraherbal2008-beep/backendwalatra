<?php

namespace App\Filament\Resources;

use App\Filament\Resources\StoreResource\Pages;
use App\Models\Store;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class StoreResource extends Resource
{
    protected static ?string $model = Store::class;

    protected static string|UnitEnum|null $navigationGroup = 'Manajemen Marketplace';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingStorefront;

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'Toko';

    protected static ?string $modelLabel = 'toko';

    protected static ?string $pluralModelLabel = 'toko';

    public static function form(Schema $schema): Schema
    {
        return $schema->components(array_merge(
            self::identityFields(),
            self::profileFields(),
            self::locationFields(),
            self::lifecycleFields(),
        ));
    }

    public static function identityFields(): array
    {
        return [
            TextInput::make('name')
                ->label('Nama toko')
                ->required()
                ->maxLength(255),
            self::ownerField(),
        ];
    }

    public static function ownerField(): Select
    {
        return Select::make('owner_user_id')
            ->label('Pemilik toko')
            ->relationship(
                name: 'owner',
                titleAttribute: 'name',
                modifyQueryUsing: function (Builder $query, ?Store $record): Builder {
                    return $query
                        ->role('store_admin')
                        ->where('user_type', 'customer')
                        ->where('status', 'active')
                        ->whereNotNull('email_verified_at')
                        ->whereDoesntHave('store', function (Builder $storeQuery) use ($record): void {
                            if ($record instanceof Store) {
                                $storeQuery->whereKeyNot($record->getKey());
                            }
                        });
                },
            )
            ->searchable()
            ->preload()
            ->required();
    }

    public static function profileFields(): array
    {
        return [
            RichEditor::make('description')
                ->label('Deskripsi')
                ->toolbarButtons([
                    ['bold', 'italic', 'underline', 'link'],
                    ['h2', 'h3'],
                    ['bulletList', 'orderedList'],
                    ['undo', 'redo'],
                ])
                ->fileAttachments(false)
                ->maxLength(5000)
                ->columnSpanFull(),
            TextInput::make('contact_phone')
                ->label('Nomor kontak')
                ->tel()
                ->maxLength(20),
            TextInput::make('contact_email')
                ->label('Email kontak')
                ->email()
                ->maxLength(255),
        ];
    }

    public static function locationFields(): array
    {
        return [
            TextInput::make('province')->maxLength(100),
            TextInput::make('city')->maxLength(100),
            TextInput::make('district')->maxLength(100),
            TextInput::make('postal_code')->maxLength(10),
            Textarea::make('address')
                ->label('Alamat')
                ->columnSpanFull(),
            TextInput::make('latitude')
                ->numeric()
                ->minValue(-90)
                ->maxValue(90),
            TextInput::make('longitude')
                ->numeric()
                ->minValue(-180)
                ->maxValue(180),
        ];
    }

    public static function lifecycleFields(): array
    {
        return [
            Select::make('status')
                ->options([
                    'pending_approval' => 'Menunggu persetujuan',
                    'active' => 'Aktif',
                    'rejected' => 'Ditolak',
                    'suspended' => 'Ditangguhkan',
                    'inactive' => 'Tidak aktif',
                ])
                ->default('pending_approval')
                ->required()
                ->disabled(fn (string $operation): bool => $operation === 'edit'),
            DateTimePicker::make('email_verified_at')->label('Email terverifikasi pada'),
            DateTimePicker::make('approved_at')->label('Disetujui pada'),
            DateTimePicker::make('opened_at')->label('Mulai beroperasi pada'),
        ];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Nama toko')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('code')
                    ->label('Kode')
                    ->searchable(),
                TextColumn::make('city')
                    ->label('Kota')
                    ->searchable(),
                TextColumn::make('status')
                    ->badge(),
                TextColumn::make('owner.name')
                    ->label('Pemilik')
                    ->placeholder('-'),
                TextColumn::make('approved_at')
                    ->label('Disetujui')
                    ->dateTime('d M Y H:i')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        'pending_approval' => 'Menunggu persetujuan',
                        'active' => 'Aktif',
                        'rejected' => 'Ditolak',
                        'suspended' => 'Ditangguhkan',
                        'inactive' => 'Tidak aktif',
                    ]),
            ])
            ->defaultSort('created_at', 'desc')
            ->actions([
                EditAction::make(),
                DeleteAction::make()
                    ->label('Hapus')
                    ->requiresConfirmation(),
                Action::make('approve')
                    ->label('Setujui')
                    ->icon('heroicon-m-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (Store $record): bool => $record->status === 'pending_approval')
                    ->action(function (Store $record): void {
                        $record->update([
                            'status' => 'active',
                            'approved_at' => now(),
                        ]);

                        Notification::make()
                            ->title('Toko berhasil disetujui')
                            ->success()
                            ->send();
                    }),
                Action::make('reject')
                    ->label('Tolak')
                    ->icon('heroicon-m-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (Store $record): bool => $record->status === 'pending_approval')
                    ->action(function (Store $record): void {
                        $record->update([
                            'status' => 'rejected',
                            'approved_at' => null,
                        ]);

                        Notification::make()
                            ->title('Toko ditolak')
                            ->warning()
                            ->send();
                    }),
                Action::make('suspend')
                    ->label('Suspend')
                    ->icon('heroicon-m-pause-circle')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->visible(fn (Store $record): bool => $record->status === 'active')
                    ->action(function (Store $record): void {
                        $record->update(['status' => 'suspended']);

                        Notification::make()
                            ->title('Toko berhasil ditangguhkan')
                            ->warning()
                            ->send();
                    }),
                Action::make('activate')
                    ->label('Aktifkan')
                    ->icon('heroicon-m-play-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (Store $record): bool => in_array($record->status, ['rejected', 'suspended', 'inactive'], true))
                    ->action(function (Store $record): void {
                        $record->update([
                            'status' => 'active',
                            'approved_at' => $record->approved_at ?? now(),
                        ]);

                        Notification::make()
                            ->title('Toko berhasil diaktifkan')
                            ->success()
                            ->send();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListStores::route('/'),
            'create' => Pages\CreateStore::route('/create'),
            'edit' => Pages\EditStore::route('/{record}/edit'),
        ];
    }
}
