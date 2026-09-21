<?php

namespace App\Filament\Admin\Resources\StoreBundleListings;

use App\Filament\Admin\Resources\StoreBundleListings\Pages\CreateStoreBundleListing;
use App\Filament\Admin\Resources\StoreBundleListings\Pages\EditStoreBundleListing;
use App\Filament\Admin\Resources\StoreBundleListings\Pages\ListStoreBundleListings;
use App\Models\ProductBundle;
use App\Models\StoreBundleListing;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\SpatieMediaLibraryImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;
use UnitEnum;

class StoreBundleListingResource extends Resource
{
    protected static ?string $model = StoreBundleListing::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|UnitEnum|null $navigationGroup = 'Toko Saya';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'Bundle Toko';

    protected static ?string $modelLabel = 'bundle toko';

    protected static ?string $pluralModelLabel = 'bundle toko';

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
            ->with('bundle');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('product_bundle_id')
                ->label('Bundle master')
                ->relationship(
                    name: 'bundle',
                    titleAttribute: 'name',
                    modifyQueryUsing: function (Builder $query, Select $component): Builder {
                        $storeId = auth()->user()?->store?->getKey();
                        $record = $component->getRecord();

                        return $query
                            ->where(function (Builder $bundleQuery) use ($record): void {
                                $bundleQuery->where('status', 'active');

                                if ($record instanceof StoreBundleListing) {
                                    $bundleQuery->orWhere('id', $record->product_bundle_id);
                                }
                            })
                            ->when(
                                $storeId,
                                fn (Builder $bundleQuery): Builder => $bundleQuery->where(function (Builder $query) use ($record, $storeId): void {
                                    $query->whereDoesntHave(
                                        'storeListings',
                                        fn (Builder $listingQuery): Builder => $listingQuery->where('store_id', $storeId),
                                    );

                                    if ($record instanceof StoreBundleListing) {
                                        $query->orWhere('id', $record->product_bundle_id);
                                    }
                                }),
                            );
                    },
                )
                ->getOptionLabelFromRecordUsing(
                    fn (ProductBundle $record): string => $record->name.' — '.$record->code,
                )
                ->searchable(['name', 'code'])
                ->preload()
                ->live()
                ->required()
                ->disabledOn('edit')
                ->helperText('Bundle master dikunci setelah listing dibuat. Untuk mengganti bundle, hapus listing ini dan buat baru.'),
            TextEntry::make('bundle_items_preview')
                ->label('Isi paket bundle')
                ->state(function (Get $get): string|Htmlable {
                    $bundleId = $get('product_bundle_id');

                    if (blank($bundleId)) {
                        return 'Pilih bundle untuk melihat isi paket.';
                    }

                    $bundle = ProductBundle::query()
                        ->with('items.variant.product')
                        ->find($bundleId);

                    if (! $bundle) {
                        return 'Data bundle tidak ditemukan.';
                    }

                    $rows = $bundle->items->map(function ($item): string {
                        $productName = e($item->variant?->product?->name ?? 'Produk tidak ditemukan');
                        $variantName = e($item->variant?->name ?? '-');
                        $sku = e($item->variant?->sku ?? '-');
                        $quantity = e((string) $item->quantity);

                        return '<tr>'
                            .'<td style="width: 30%; padding: 12px 16px; border-top: 1px solid #35363d; color: #f3f4f6; font-weight: 600;">'.$productName.'</td>'
                            .'<td style="width: 25%; padding: 12px 16px; border-top: 1px solid #35363d; color: #d1d5db;">'.$variantName.'</td>'
                            .'<td style="width: 30%; padding: 12px 16px; border-top: 1px solid #35363d; color: #aeb3bf; font-family: monospace; font-size: 12px; white-space: nowrap;">'.$sku.'</td>'
                            .'<td style="width: 15%; padding: 12px 16px; border-top: 1px solid #35363d; color: #f3f4f6; font-weight: 700; text-align: center;">'.$quantity.'</td>'
                            .'</tr>';
                    })->implode('');

                    return new HtmlString(
                        '<div style="width: 100%; overflow-x: auto; border: 1px solid #35363d; border-radius: 10px; background: #17181c;">'
                            .'<table style="width: 100%; min-width: 680px; border-collapse: separate; border-spacing: 0; table-layout: fixed; font-size: 13px; text-align: left;">'
                            .'<thead style="background: #24252c;">'
                            .'<tr>'
                            .'<th style="width: 30%; padding: 11px 16px; color: #c7cad3; font-size: 11px; font-weight: 700; letter-spacing: .04em; text-transform: uppercase;">Produk</th>'
                            .'<th style="width: 25%; padding: 11px 16px; color: #c7cad3; font-size: 11px; font-weight: 700; letter-spacing: .04em; text-transform: uppercase;">Varian</th>'
                            .'<th style="width: 30%; padding: 11px 16px; color: #c7cad3; font-size: 11px; font-weight: 700; letter-spacing: .04em; text-transform: uppercase;">SKU</th>'
                            .'<th style="width: 15%; padding: 11px 16px; color: #c7cad3; font-size: 11px; font-weight: 700; letter-spacing: .04em; text-align: center; text-transform: uppercase;">Jumlah</th>'
                            .'</tr>'
                            .'</thead>'
                            .'<tbody>'.$rows.'</tbody>'
                            .'</table>'
                            .'</div>',
                    );
                })
                ->html()
                ->columnSpanFull(),
            RichEditor::make('description')
                ->label('Deskripsi bundle di toko')
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
                ->label('Gambar bundle di toko')
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
                TextColumn::make('bundle.name')
                    ->label('Bundle')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('bundle.code')
                    ->label('Kode bundle')
                    ->searchable(),
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
            'index' => ListStoreBundleListings::route('/'),
            'create' => CreateStoreBundleListing::route('/create'),
            'edit' => EditStoreBundleListing::route('/{record}/edit'),
        ];
    }
}
