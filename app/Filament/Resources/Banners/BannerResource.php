<?php

namespace App\Filament\Resources\Banners;

use App\Models\Banner;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

class BannerResource extends Resource
{
    protected static ?string $model = Banner::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPhoto;

    protected static string|UnitEnum|null $navigationGroup = 'Manajemen Marketplace';

    protected static ?int $navigationSort = 12;

    protected static ?string $navigationLabel = 'Banner Iklan';

    protected static ?string $modelLabel = 'banner iklan';

    protected static ?string $pluralModelLabel = 'banner iklan';

    public static function canAccess(): bool
    {
        return auth()->user()?->hasRole('super_admin') ?? false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->label('Nama iklan')
                ->required()
                ->maxLength(255),
            SpatieMediaLibraryFileUpload::make('image')
                ->label('Gambar banner')
                ->collection('image')
                ->disk('public')
                ->image()
                ->required(fn (string $operation): bool => $operation === 'create')
                ->maxSize(5120)
                ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                ->helperText('Rekomendasi sekitar 2115 × 743 px dengan rasio 2.85:1. Maksimal 5 MB.')
                ->columnSpanFull(),
            TextInput::make('cta_url')
                ->label('URL CTA')
                ->url()
                ->required()
                ->maxLength(2048)
                ->helperText('URL yang dibuka ketika banner dipilih di Android atau website.'),
            Select::make('status')
                ->label('Status')
                ->options([
                    'draft' => 'Draft',
                    'active' => 'Aktif',
                    'inactive' => 'Nonaktif',
                ])
                ->default('draft')
                ->required(),
            DateTimePicker::make('starts_at')
                ->label('Mulai tampil')
                ->seconds(false),
            DateTimePicker::make('ends_at')
                ->label('Berakhir tampil')
                ->seconds(false)
                ->after('starts_at'),
            TextInput::make('sort_order')
                ->label('Urutan tampil')
                ->numeric()
                ->minValue(1)
                ->default(100)
                ->required()
                ->helperText('Angka lebih kecil tampil lebih dahulu.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                ImageColumn::make('image_url')
                    ->label('Banner')
                    ->state(fn (Banner $record): ?string => $record->getFirstMediaUrl('image') ?: null)
                    ->height(60),
                TextColumn::make('name')
                    ->label('Nama iklan')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge(),
                TextColumn::make('starts_at')
                    ->label('Mulai')
                    ->dateTime('d M Y H:i')
                    ->placeholder('Tanpa batas mulai')
                    ->sortable(),
                TextColumn::make('ends_at')
                    ->label('Berakhir')
                    ->dateTime('d M Y H:i')
                    ->placeholder('Tanpa batas akhir')
                    ->sortable(),
                TextColumn::make('sort_order')
                    ->label('Urutan')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options([
                        'draft' => 'Draft',
                        'active' => 'Aktif',
                        'inactive' => 'Nonaktif',
                    ]),
            ])
            ->recordActions([
                EditAction::make()
                    ->successNotificationTitle('Banner iklan berhasil diperbarui.'),
                DeleteAction::make()
                    ->label('Hapus')
                    ->successNotificationTitle('Banner iklan berhasil dihapus.')
                    ->requiresConfirmation(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->successNotificationTitle('Banner iklan berhasil dihapus.'),
                ]),
            ])
            ->defaultSort('sort_order');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBanners::route('/'),
            'create' => Pages\CreateBanner::route('/create'),
            'edit' => Pages\EditBanner::route('/{record}/edit'),
        ];
    }
}
