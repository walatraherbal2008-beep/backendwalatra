<?php

namespace App\Filament\Resources\ShippingSubsidyRules;

use App\Models\ShippingSubsidyRule;
use BackedEnum;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class ShippingSubsidyRuleResource extends Resource
{
    protected static ?string $model = ShippingSubsidyRule::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTruck;

    protected static string|UnitEnum|null $navigationGroup = 'Manajemen Marketplace';

    protected static ?int $navigationSort = 11;

    protected static ?string $navigationLabel = 'Aturan Gratis Ongkir';

    protected static ?string $modelLabel = 'aturan gratis ongkir';

    protected static ?string $pluralModelLabel = 'aturan gratis ongkir';

    public static function canAccess(): bool
    {
        return auth()->user()?->hasRole('super_admin') ?? false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label('Nama aturan')->required()->maxLength(255),
            Select::make('scope_type')
                ->label('Cakupan wilayah')
                ->options([
                    'all' => 'Seluruh Indonesia',
                    'province' => 'Provinsi',
                    'city' => 'Kota/Kabupaten',
                    'district' => 'Kecamatan',
                    'sub_district' => 'Kelurahan/Desa',
                ])
                ->live()
                ->required(),
            TextInput::make('scope_code')
                ->label('Kode wilayah')
                ->helperText('Isi dengan ID Everpro sesuai cakupan yang dipilih.')
                ->visible(fn ($get): bool => $get('scope_type') !== 'all')
                ->required(fn ($get): bool => $get('scope_type') !== 'all')
                ->maxLength(100),
            TextInput::make('scope_name')->label('Nama wilayah')->maxLength(255),
            TextInput::make('min_subtotal')->label('Minimal subtotal')->numeric()->prefix('Rp')->default(0)->required(),
            TextInput::make('max_subsidy')->label('Maksimal subsidi')->numeric()->prefix('Rp')->minValue(0)->required(),
            TextInput::make('max_weight_grams')->label('Maksimal berat (gram)')->numeric()->minValue(0),
            TextInput::make('priority')->label('Prioritas')->numeric()->minValue(0)->default(0)->required(),
            Toggle::make('enabled')->label('Aktif')->default(false),
            DateTimePicker::make('starts_at')->label('Mulai berlaku'),
            DateTimePicker::make('ends_at')->label('Berakhir'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->label('Nama')->searchable(),
            TextColumn::make('scope_name')->label('Wilayah')->formatStateUsing(fn ($state, ShippingSubsidyRule $record): string => $record->scope_type === 'all' ? 'Seluruh Indonesia' : (($state ?: '-') . ' (' . ($record->scope_code ?: '-') . ')')),
            TextColumn::make('min_subtotal')->label('Min. belanja')->money('IDR'),
            TextColumn::make('max_subsidy')->label('Maks. subsidi')->money('IDR'),
            TextColumn::make('priority')->label('Prioritas')->sortable(),
            IconColumn::make('enabled')->label('Aktif')->boolean(),
        ])->defaultSort('priority', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListShippingSubsidyRules::route('/'),
            'create' => Pages\CreateShippingSubsidyRule::route('/create'),
            'edit' => Pages\EditShippingSubsidyRule::route('/{record}/edit'),
        ];
    }
}
