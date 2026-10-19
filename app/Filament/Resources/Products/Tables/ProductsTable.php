<?php

namespace App\Filament\Resources\Products\Tables;

use App\Models\Category;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\BulkAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ProductsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Nama produk')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('product_code')
                    ->label('Kode produk')
                    ->searchable(),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge(),
                TextColumn::make('variants_count')
                    ->label('Jumlah varian')
                    ->counts('variants'),
                TextColumn::make('categories_count')
                    ->label('Jumlah kategori')
                    ->counts('categories'),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options([
                        'draft' => 'Draft',
                        'active' => 'Aktif',
                        'inactive' => 'Tidak aktif',
                    ]),
                Filter::make('without_category')
                    ->label('Tanpa kategori')
                    ->query(fn (Builder $query): Builder => $query->whereDoesntHave('categories')),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('assign_category')
                        ->label('Tambahkan kategori')
                        ->form([
                            Select::make('category_id')
                                ->label('Kategori aktif')
                                ->options(fn (): array => Category::query()
                                    ->where('status', 'active')
                                    ->orderBy('sort_order')
                                    ->orderBy('name')
                                    ->pluck('name', 'id')
                                    ->all())
                                ->searchable()
                                ->preload()
                                ->required(),
                        ])
                        ->action(function (Collection $records, array $data): void {
                            $records->each(function ($product) use ($data): void {
                                $product->categories()->syncWithoutDetaching([$data['category_id']]);
                            });
                        })
                        ->successNotificationTitle('Kategori produk berhasil ditambahkan.'),
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
