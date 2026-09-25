<?php

namespace App\Filament\Resources\Products\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class ProductForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('Nama produk')
                    ->required()
                    ->maxLength(255),
                TextInput::make('product_code')
                    ->label('Kode produk')
                    ->maxLength(100)
                    ->disabled()
                    ->dehydrated(false)
                    ->hiddenOn('create')
                    ->helperText('Kode produk dibuat otomatis dan tidak dapat diubah.'),
                TextInput::make('bpom_number')
                    ->label('Nomor BPOM')
                    ->maxLength(100),
                SpatieMediaLibraryFileUpload::make('images')
                    ->label('Gambar produk (pusat)')
                    ->collection('images')
                    ->image()
                    ->multiple()
                    ->reorderable()
                    ->appendFiles()
                    ->maxFiles(10)
                    ->maxSize(5120)
                    ->panelLayout('grid')
                    ->helperText('Maksimal 10 gambar, masing-masing 5 MB. Dipakai sebagai fallback gambar etalase toko yang belum mengunggah gambar sendiri.')
                    ->columnSpanFull(),
                Select::make('categories')
                    ->label('Kategori produk')
                    ->relationship(
                        name: 'categories',
                        titleAttribute: 'name',
                        modifyQueryUsing: fn ($query) => $query->where('status', 'active')->orderBy('sort_order'),
                    )
                    ->multiple()
                    ->searchable()
                    ->preload()
                    ->helperText('Pilih satu atau beberapa kategori untuk produk ini.')
                    ->columnSpanFull(),
                Select::make('status')
                    ->label('Status')
                    ->options(fn (string $operation): array => $operation === 'create'
                        ? ['draft' => 'Draft']
                        : [
                            'draft' => 'Draft',
                            'active' => 'Aktif',
                            'inactive' => 'Tidak aktif',
                        ])
                    ->default('draft')
                    ->required(),
            ]);
    }
}
