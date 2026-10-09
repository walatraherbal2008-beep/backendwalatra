<?php

namespace App\Filament\Resources\MarketplaceSettings;

use App\Models\MarketplaceSetting;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class MarketplaceSettingResource extends Resource
{
    protected static ?string $model = MarketplaceSetting::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;

    protected static string|UnitEnum|null $navigationGroup = 'Manajemen Marketplace';

    protected static ?int $navigationSort = 10;

    protected static ?string $navigationLabel = 'Pengaturan';

    protected static ?string $modelLabel = 'pengaturan marketplace';

    protected static ?string $pluralModelLabel = 'pengaturan marketplace';

    public static function canAccess(): bool
    {
        return auth()->user()?->hasRole('super_admin') ?? false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereIn('key', [
            'self_service_store_registration_enabled',
            'self_service_store_admin_invitation_enabled',
        ]);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('key')
                ->label('Kode pengaturan')
                ->disabled()
                ->dehydrated(false),
            Toggle::make('enabled')
                ->label('Aktif')
                ->helperText('Perubahan langsung memengaruhi endpoint pendaftaran mandiri.')
                ->required(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('setting_label')
                    ->label('Pengaturan')
                    ->state(fn (MarketplaceSetting $record): string => MarketplaceSetting::label($record->key)),
                TextColumn::make('key')
                    ->label('Kode')
                    ->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('enabled')
                    ->label('Aktif')
                    ->boolean(),
                TextColumn::make('updated_at')
                    ->label('Diperbarui')
                    ->dateTime('d M Y H:i')
                    ->sortable(),
            ])
            ->recordActions([EditAction::make()])
            ->defaultSort('key');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMarketplaceSettings::route('/'),
            'edit' => Pages\EditMarketplaceSetting::route('/{record}/edit'),
        ];
    }
}
