<?php

namespace App\Filament\Resources\WarehouseStocks;

use App\Filament\Resources\WarehouseStocks\Pages\ListWarehouseStockSnapshots;
use App\Models\WarehouseProductStockSnapshot;
use App\Services\Warehouse\WarehouseProductStockSnapshotSync;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class WarehouseStockSnapshotResource extends Resource
{
    protected static ?string $model = WarehouseProductStockSnapshot::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArchiveBox;

    protected static string|UnitEnum|null $navigationGroup = 'Pengiriman';

    protected static ?int $navigationSort = 20;

    protected static ?string $navigationLabel = 'Stok Gudang';

    protected static ?string $modelLabel = 'stok gudang';

    protected static ?string $pluralModelLabel = 'Stok Gudang';

    public static function canAccess(): bool
    {
        return auth()->user()?->status === 'active' && auth()->user()->hasRole('super_admin');
    }

    public static function canViewAny(): bool
    {
        return self::canAccess();
    }

    public static function canView(Model $record): bool
    {
        return self::canAccess();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        abort_unless(self::canAccess(), 403);

        return parent::getEloquentQuery()->with([
            'warehouse:id,code,realname',
            'product:id,product_code,name',
        ]);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->heading('Stok produk per gudang')
            ->description('Jumlah berasal dari pengecekan API terakhir. Stok dapat berubah setelah diperiksa dan belum berarti sudah direservasi.')
            ->defaultSort('last_attempted_at', 'desc')
            ->columns([
                TextColumn::make('warehouse.realname')->label('Gudang')->searchable()->sortable(),
                TextColumn::make('warehouse.code')->label('Kode gudang')->searchable()->copyable(),
                TextColumn::make('product.product_code')->label('Kode produk')->searchable()->copyable(),
                TextColumn::make('product.name')->label('Produk')->searchable()->sortable()->wrap(),
                TextColumn::make('reported_quantity')->label('Stok tercatat API')->numeric()->placeholder('Belum diperiksa')->sortable()
                    ->color(fn (?int $state): string => $state !== null && $state < 0 ? 'danger' : 'gray'),
                TextColumn::make('available_quantity')->label('Stok dapat dibeli')->numeric()->placeholder('Belum diperiksa')->sortable(),
                TextColumn::make('status')->label('Status')->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'succeeded' => 'Berhasil', 'failed' => 'Gagal', default => 'Belum diperiksa',
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'succeeded' => 'success', 'failed' => 'danger', default => 'gray',
                    }),
                TextColumn::make('checked_at')->label('Terakhir berhasil')->dateTime('d M Y H:i:s', timezone: 'Asia/Jakarta')->placeholder('Belum tersedia')->sortable(),
                TextColumn::make('last_error')->label('Keterangan')->limit(60)->placeholder('-')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('warehouse')->label('Gudang')->relationship('warehouse', 'realname')->searchable(),
                SelectFilter::make('status')->label('Status')->options([
                    'pending' => 'Belum diperiksa',
                    'succeeded' => 'Berhasil',
                    'failed' => 'Gagal',
                ]),
            ])
            ->recordActions([
                Action::make('refreshStock')
                    ->label('Perbarui stok')
                    ->icon(Heroicon::OutlinedArrowPath)
                    ->authorize(fn (): bool => self::canAccess())
                    ->action(function (WarehouseProductStockSnapshot $record): void {
                        abort_unless(self::canAccess(), 403);

                        $result = app(WarehouseProductStockSnapshotSync::class)->sync(
                            warehouseId: $record->warehouse_id,
                            productId: $record->product_id,
                        );
                        $record->refresh();

                        if ($result['succeeded'] > 0) {
                            Notification::make()->title('Stok berhasil diperbarui')
                                ->body("Stok API: {$record->reported_quantity}; dapat dibeli: {$record->available_quantity}")
                                ->success()->send();

                            return;
                        }

                        Notification::make()->title($result['checked'] === 0 ? 'Pasangan gudang-produk tidak aktif' : 'Stok gagal diperbarui')
                            ->body($record->last_error ?? 'Periksa status gudang, penetapan produk, dan log API eksternal.')
                            ->warning()->send();
                    }),
            ])
            ->recordActionsColumnLabel('Aksi')
            ->paginationPageOptions([10, 25, 50])
            ->emptyStateHeading('Belum ada snapshot stok')
            ->emptyStateDescription('Snapshot akan muncul setelah perintah warehouses:sync-stock berjalan.');
    }

    public static function getPages(): array
    {
        return ['index' => ListWarehouseStockSnapshots::route('/')];
    }
}
