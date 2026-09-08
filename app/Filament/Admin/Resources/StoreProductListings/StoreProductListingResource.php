<?php

namespace App\Filament\Admin\Resources\StoreProductListings;

use App\Filament\Admin\Resources\StoreProductListings\Pages\CreateStoreProductListing;
use App\Filament\Admin\Resources\StoreProductListings\Pages\EditStoreProductListing;
use App\Filament\Admin\Resources\StoreProductListings\Pages\ListStoreProductListings;
use App\Models\StoreProductListing;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\SpatieMediaLibraryImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class StoreProductListingResource extends Resource
{
    protected static ?string $model = StoreProductListing::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingBag;

    protected static string|UnitEnum|null $navigationGroup = 'Toko Saya';

    protected static ?int $navigationSort = 1;

    protected static ?string $navigationLabel = 'Produk Toko';

    protected static ?string $modelLabel = 'produk toko';

    protected static ?string $pluralModelLabel = 'produk toko';

    public static function canAccess(): bool
    {
        return auth()->user()?->canAccessPanel(filament()->getPanel('admin')) ?? false;
    }

    public static function getEloquentQuery(): Builder
    {
        $storeId = auth()->user()?->store?->getKey();

        return parent::getEloquentQuery()
            ->when($storeId, fn (Builder $query): Builder => $query->where('store_id', $storeId))
            ->when(! $storeId, fn (Builder $query): Builder => $query->whereRaw('1 = 0'))
            ->with('product');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('product_id')
                ->label('Produk master')
                ->relationship(
                    name: 'product',
                    titleAttribute: 'name',
                    modifyQueryUsing: function (Builder $query, Select $component): Builder {
                        $storeId = auth()->user()?->store?->getKey();
                        $record = $component->getRecord();

                        return $query
                            ->where(function (Builder $productQuery) use ($record): void {
                                $productQuery->where('status', 'active');

                                if ($record instanceof StoreProductListing) {
                                    $productQuery->orWhere('id', $record->product_id);
                                }
                            })
                            ->when(
                                $storeId,
                                fn (Builder $productQuery): Builder => $productQuery->where(function (Builder $query) use ($record, $storeId): void {
                                    $query->whereDoesntHave(
                                        'listings',
                                        fn (Builder $listingQuery): Builder => $listingQuery->where('store_id', $storeId),
                                    );

                                    if ($record instanceof StoreProductListing) {
                                        $query->orWhere('id', $record->product_id);
                                    }
                                }),
                            );
                    },
                )
                ->searchable()
                ->preload()
                ->required()
                ->disabled(fn (string $operation): bool => $operation === 'edit'),
            RichEditor::make('description')
                ->label('Deskripsi produk di toko')
                ->toolbarButtons([
                    ['bold', 'italic', 'underline', 'link'],
                    ['h2', 'h3'],
                    ['bulletList', 'orderedList'],
                    ['undo', 'redo'],
                ])
                ->fileAttachments(false)
                ->maxLength(5000)
                ->columnSpanFull(),
            Select::make('status')
                ->label('Status tayang')
                ->options([
                    'draft' => 'Draft',
                    'active' => 'Aktif',
                    'inactive' => 'Tidak aktif',
                ])
                ->default('draft')
                ->required(),
            TextInput::make('sort_order')
                ->label('Urutan tampil')
                ->numeric()
                ->minValue(0)
                ->default(0)
                ->required(),
            SpatieMediaLibraryFileUpload::make('images')
                ->label('Gambar produk di toko')
                ->collection('images')
                ->image()
                ->multiple()
                ->reorderable()
                ->appendFiles()
                ->maxFiles(10)
                ->maxSize(5120)
                ->panelLayout('grid')
                ->helperText('Maksimal 10 gambar, masing-masing 5 MB.')
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                SpatieMediaLibraryImageColumn::make('images')
                    ->label('Gambar')
                    ->collection('images')
                    ->circular(),
                TextColumn::make('product.name')
                    ->label('Produk')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('product.product_code')
                    ->label('Kode produk')
                    ->searchable(),
                TextColumn::make('product.categories.name')
                    ->label('Kategori')
                    ->badge(),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge(),
                TextColumn::make('sort_order')
                    ->label('Urutan')
                    ->sortable(),
                TextColumn::make('updated_at')
                    ->label('Diperbarui')
                    ->dateTime('d M Y H:i')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options([
                        'draft' => 'Draft',
                        'active' => 'Aktif',
                        'inactive' => 'Tidak aktif',
                    ]),
            ])
            ->defaultSort('sort_order')
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()
                    ->label('Hapus')
                    ->requiresConfirmation(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStoreProductListings::route('/'),
            'create' => CreateStoreProductListing::route('/create'),
            'edit' => EditStoreProductListing::route('/{record}/edit'),
        ];
    }
}
