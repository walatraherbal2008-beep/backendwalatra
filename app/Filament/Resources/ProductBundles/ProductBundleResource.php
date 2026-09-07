<?php

namespace App\Filament\Resources\ProductBundles;

use App\Filament\Resources\ProductBundles\Pages\CreateProductBundle;
use App\Filament\Resources\ProductBundles\Pages\EditProductBundle;
use App\Filament\Resources\ProductBundles\Pages\ListProductBundles;
use App\Filament\Resources\ProductBundles\Schemas\ProductBundleForm;
use App\Filament\Resources\ProductBundles\Tables\ProductBundlesTable;
use App\Models\ProductBundle;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class ProductBundleResource extends Resource
{
    protected static ?string $model = ProductBundle::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|UnitEnum|null $navigationGroup = 'Katalog';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'Bundle / Paket';

    protected static ?string $modelLabel = 'bundle';

    protected static ?string $pluralModelLabel = 'bundle';

    public static function form(Schema $schema): Schema
    {
        return ProductBundleForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ProductBundlesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProductBundles::route('/'),
            'create' => CreateProductBundle::route('/create'),
            'edit' => EditProductBundle::route('/{record}/edit'),
        ];
    }
}
