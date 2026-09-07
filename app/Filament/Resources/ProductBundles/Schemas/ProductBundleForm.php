<?php

namespace App\Filament\Resources\ProductBundles\Schemas;

use App\Models\ProductVariant;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

class ProductBundleForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('Nama bundle')
                    ->required()
                    ->maxLength(255),
                TextInput::make('price')
                    ->label('Harga bundle')
                    ->numeric()
                    ->prefix('Rp')
                    ->minValue(0)
                    ->required(),
                Select::make('status')
                    ->label('Status')
                    ->options([
                        'active' => 'Aktif',
                        'inactive' => 'Tidak aktif',
                    ])
                    ->default('active')
                    ->required(),
                RichEditor::make('description')
                    ->label('Deskripsi bundle')
                    ->toolbarButtons([
                        ['bold', 'italic', 'underline'],
                        ['bulletList', 'orderedList'],
                        ['undo', 'redo'],
                    ])
                    ->fileAttachments(false)
                    ->maxLength(5000)
                    ->columnSpanFull(),
                Repeater::make('items')
                    ->label('Komponen bundle')
                    ->relationship()
                    ->schema([
                        Select::make('product_variant_id')
                            ->label('Produk / varian')
                            ->relationship(
                                name: 'variant',
                                titleAttribute: 'name',
                                modifyQueryUsing: fn (Builder $query): Builder => $query
                                    ->where('status', 'active')
                                    ->with('product'),
                            )
                            ->getOptionLabelFromRecordUsing(
                                fn (ProductVariant $record): string => $record->product->name.' — '.$record->name,
                            )
                            ->searchable(['name', 'sku'])
                            ->preload()
                            ->required()
                            ->distinct(),
                        TextInput::make('quantity')
                            ->label('Jumlah')
                            ->numeric()
                            ->minValue(1)
                            ->default(1)
                            ->required(),
                    ])
                    ->columns(2)
                    ->addActionLabel('Tambah Komponen')
                    ->minItems(1)
                    ->columnSpanFull(),
            ]);
    }
}
