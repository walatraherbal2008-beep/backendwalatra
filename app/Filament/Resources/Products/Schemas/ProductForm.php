<?php

namespace App\Filament\Resources\Products\Schemas;

use Filament\Forms\Components\Select;
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
                    ->required()
                    ->maxLength(100)
                    ->unique(ignoreRecord: true)
                    ->helperText('Kode produk dikelola manual oleh Superadmin.'),
                TextInput::make('bpom_number')
                    ->label('Nomor BPOM')
                    ->maxLength(100),
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
                    ->options([
                        'draft' => 'Draft',
                        'active' => 'Aktif',
                        'inactive' => 'Tidak aktif',
                    ])
                    ->default('draft')
                    ->required(),
            ]);
    }
}
