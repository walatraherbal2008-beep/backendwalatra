<?php

namespace App\Filament\Resources\StoreTransactions;

use App\Exceptions\PaymentGatewayUnavailableException;
use App\Exceptions\PaymentOperationInProgress;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\StoreTransactions\Pages\ManageStoreTransactions;
use App\Models\Order;
use App\Models\Store;
use App\Services\Marketplace\StoreTransactionReport;
use App\Services\Payments\OrderPayments;
use App\Support\PaymentMethods;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Exceptions\HttpResponseException;
use UnitEnum;

class StoreTransactionResource extends Resource
{
    protected static ?string $model = Order::class;

    protected static ?string $slug = 'transaksi-toko';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static string|UnitEnum|null $navigationGroup = 'Manajemen Marketplace';

    protected static ?int $navigationSort = 5;

    protected static ?string $navigationLabel = 'Transaksi Toko';

    protected static ?string $modelLabel = 'transaksi toko';

    protected static ?string $pluralModelLabel = 'Transaksi Toko';

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
        return StoreTransactionReport::query();
    }

    private static function environmentLabel(string $state): string
    {
        return match ($state) {
            'sandbox' => 'Sandbox', 'production' => 'Production', 'manual' => 'Manual', default => 'Belum diketahui'
        };
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('order_number')->label('Nomor order')->searchable()->copyable(),
            TextColumn::make('user.name')->label('Customer')->searchable()->placeholder('Belum melengkapi nama')->wrap(),
            TextColumn::make('user.phone')->label('WhatsApp')->searchable()->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('stores')->label('Toko saat pembelian')
                ->state(fn (Order $record): array => $record->items->pluck('store_name')->filter()->unique()->values()->all())
                ->listWithLineBreaks()->limitList(3)->expandableLimitedList()->placeholder('Belum tercatat'),
            TextColumn::make('payment_provider')->label('Penyedia')->badge(),
            TextColumn::make('activePayment.method')->label('Metode')
                ->formatStateUsing(fn (string $state): string => PaymentMethods::LABELS[$state] ?? $state)->placeholder('-'),
            TextColumn::make('environment')->label('Lingkungan')->state(fn (Order $record): string => StoreTransactionReport::environment($record))
                ->formatStateUsing(self::environmentLabel(...))->badge()
                ->color(fn (string $state): string => match ($state) {
                    'production' => 'success', 'sandbox' => 'warning', default => 'gray'
                }),
            TextColumn::make('status')->label('Status order')->badge()->wrap()
                ->formatStateUsing(fn (string $state): string => StoreTransactionReport::STATUSES[$state] ?? $state)
                ->color(fn (string $state): string => match ($state) {
                    'paid' => 'success', 'payment_review' => 'danger', 'pending_payment' => 'warning', default => 'gray'
                }),
            TextColumn::make('store_subtotal')->label('Subtotal barang toko')->money('IDR')
                ->visible(fn (ManageStoreTransactions $livewire): bool => $livewire->selectedStoreId() !== null),
            TextColumn::make('total')->label('Total pembayaran order')->money('IDR')->sortable(),
            TextColumn::make('created_at')->label('Dibuat (WIB)')->dateTime('d M Y H:i', timezone: 'Asia/Jakarta')->sortable(),
        ])->filters([
            SelectFilter::make('store')->label('Toko')->searchable()
                ->options(fn (): array => Store::query()->orderBy('name')->get()->mapWithKeys(fn (Store $store): array => [$store->id => $store->name.' ('.$store->code.')'])->all())
                ->query(fn (Builder $query): Builder => $query)
                ->baseQuery(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
                    ? StoreTransactionReport::filterStore($query, (int) $data['value']) : $query),
            SelectFilter::make('status')->label('Status order')->options(StoreTransactionReport::STATUSES),
            SelectFilter::make('payment_provider')->label('Penyedia pembayaran')->options(['manual' => 'Manual', 'midtrans' => 'Midtrans']),
            SelectFilter::make('environment')->label('Lingkungan Midtrans')
                ->options(['sandbox' => 'Sandbox', 'production' => 'Production', 'unknown' => 'Belum diketahui'])
                ->query(function (Builder $query, array $data): Builder {
                    $value = $data['value'] ?? null;
                    if (blank($value)) {
                        return $query;
                    }
                    $query->where('payment_provider', 'midtrans');

                    return $value === 'unknown'
                        ? $query->whereDoesntHave('activePayment')
                        : $query->whereHas('activePayment', fn (Builder $payment) => $payment->where('environment', $value));
                }),
            Filter::make('created_at')->label('Tanggal pembuatan')->schema([
                DatePicker::make('from')->label('Dari tanggal')->native(false),
                DatePicker::make('until')->label('Sampai tanggal')->native(false)->afterOrEqual('from'),
            ])->query(function (Builder $query, array $data): Builder {
                return $query
                    ->when(filled($data['from'] ?? null), fn (Builder $query) => $query->where('orders.created_at', '>=', StoreTransactionReport::dateBoundary($data['from'])))
                    ->when(filled($data['until'] ?? null), fn (Builder $query) => $query->where('orders.created_at', '<', StoreTransactionReport::dateBoundary($data['until'], nextDay: true)));
            })->indicateUsing(fn (array $data): array => array_filter([
                filled($data['from'] ?? null) ? 'Dari: '.$data['from'].' WIB' : null,
                filled($data['until'] ?? null) ? 'Sampai: '.$data['until'].' WIB' : null,
            ])),
        ])->deferFilters(false)->recordActions([
            ViewAction::make()->label('Detail')->slideOver()->modalWidth('4xl')
                ->schema(fn (Schema $schema): Schema => self::infolist($schema))
                ->extraModalFooterActions([
                    self::syncAction(),
                    Action::make('manageOrder')->label('Buka Pesanan')->url(fn (Order $record): string => OrderResource::getUrl('index', [
                        'search' => $record->order_number,
                    ])),
                ]),
            self::syncAction(),
        ])->defaultSort('created_at', 'desc')->recordAction('view')->paginationPageOptions([10, 25, 50])
            ->emptyStateHeading('Belum ada transaksi sesuai filter')
            ->emptyStateDescription('Transaksi manual dan Midtrans akan muncul setelah customer checkout.');
    }

    private static function syncAction(): Action
    {
        return Action::make('syncPayment')->label('Sinkronkan pembayaran')->icon(Heroicon::OutlinedArrowPath)
            ->visible(fn (Order $record): bool => $record->payment_provider === 'midtrans')
            ->action(function (Order $record, ManageStoreTransactions $livewire): void {
                abort_unless(self::canAccess() && $record->payment_provider === 'midtrans', 403);
                try {
                    app(OrderPayments::class)->refresh($record);
                    Notification::make()->title('Status pembayaran berhasil disinkronkan')->success()->send();
                } catch (PaymentOperationInProgress) {
                    Notification::make()->title('Pembayaran sedang diproses')->body('Tunggu sebentar lalu sinkronkan kembali.')->warning()->send();
                } catch (PaymentGatewayUnavailableException) {
                    Notification::make()->title('Midtrans belum dapat dikonfirmasi')->body('Status terakhir tetap ditampilkan. Silakan coba lagi.')->danger()->send();
                } catch (HttpResponseException) {
                    Notification::make()->title('Status pembayaran belum dapat disinkronkan')->body('Periksa kembali detail order sebelum mencoba lagi.')->warning()->send();
                }
                $record->refresh();
                $record->load([
                    'items.productListing' => fn ($query) => $query->withTrashed(),
                    'items.bundleListing' => fn ($query) => $query->withTrashed(),
                    'statusHistories.createdBy', 'payments', 'activePayment',
                ]);
                $livewire->flushCachedTableRecords();
                $livewire->dispatch('store-transactions-refreshed');
            });
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Order dan customer')->schema([
                TextEntry::make('order_number')->label('Nomor order'),
                TextEntry::make('status')->label('Status order')->formatStateUsing(fn (string $state): string => StoreTransactionReport::STATUSES[$state] ?? $state),
                TextEntry::make('user.name')->label('Customer')->placeholder('Belum melengkapi nama'),
                TextEntry::make('user.phone')->label('WhatsApp'),
                TextEntry::make('created_at')->label('Dibuat (WIB)')->dateTime('d M Y H:i', timezone: 'Asia/Jakarta'),
                TextEntry::make('paid_at')->label('Lunas (WIB)')->dateTime('d M Y H:i', timezone: 'Asia/Jakarta')->placeholder('-'),
                TextEntry::make('subtotal')->label('Subtotal seluruh barang')->money('IDR'),
                TextEntry::make('shipping_gross_fee')->label('Ongkir kotor')->money('IDR'),
                TextEntry::make('shipping_subsidy')->label('Subsidi ongkir')->money('IDR'),
                TextEntry::make('shipping_fee')->label('Ongkir bersih')->money('IDR'),
                TextEntry::make('total')->label('Total pembayaran seluruh order')->money('IDR'),
            ])->columns(['default' => 1, 'md' => 2]),
            Section::make('Barang toko terpilih')->visible(fn (ManageStoreTransactions $livewire): bool => $livewire->selectedStoreId() !== null)->schema([
                TextEntry::make('selected_store_subtotal')->label('Subtotal barang toko')->money('IDR')
                    ->state(fn (Order $record, ManageStoreTransactions $livewire) => StoreTransactionReport::itemsForStore($record->items()->getQuery(), $livewire->selectedStoreId())->sum('subtotal')),
                RepeatableEntry::make('selected_store_items')->label('Barang')->state(fn (Order $record, ManageStoreTransactions $livewire) => StoreTransactionReport::selectedItems($record, $livewire->selectedStoreId())->all())
                    ->schema(self::itemEntries()),
            ]),
            RepeatableEntry::make('items')->label('Seluruh barang order')->schema(self::itemEntries()),
            Section::make('Pembayaran')->schema([
                TextEntry::make('payment_provider')->label('Penyedia'),
                TextEntry::make('environment')->label('Lingkungan')->state(fn (Order $record): string => self::environmentLabel(StoreTransactionReport::environment($record))),
                TextEntry::make('payment_reference')->label('Referensi pembayaran')->placeholder('-'),
                TextEntry::make('payment_due_at')->label('Batas pembayaran (WIB)')->dateTime('d M Y H:i', timezone: 'Asia/Jakarta')->placeholder('-'),
                TextEntry::make('payment_last_synced_at')->label('Sinkronisasi terakhir (WIB)')->dateTime('d M Y H:i', timezone: 'Asia/Jakarta')->placeholder('Belum disinkronkan'),
                RepeatableEntry::make('payments')->label('Seluruh percobaan pembayaran')->schema([
                    TextEntry::make('environment')->label('Lingkungan')->formatStateUsing(self::environmentLabel(...)),
                    TextEntry::make('method')->label('Metode')->formatStateUsing(fn (string $state): string => PaymentMethods::LABELS[$state] ?? $state),
                    TextEntry::make('state')->label('Status percobaan'),
                    TextEntry::make('transaction_status')->label('Status Midtrans')->placeholder('-'),
                    TextEntry::make('provider_order_id')->label('Referensi transaksi'),
                    TextEntry::make('transaction_id')->label('ID Midtrans')->placeholder('-'),
                    TextEntry::make('amount')->label('Nominal seluruh order')->money('IDR'),
                    TextEntry::make('expires_at')->label('Berlaku sampai (WIB)')->dateTime('d M Y H:i', timezone: 'Asia/Jakarta')->placeholder('-'),
                    TextEntry::make('last_synced_at')->label('Disinkronkan (WIB)')->dateTime('d M Y H:i', timezone: 'Asia/Jakarta')->placeholder('-'),
                ])->columns(['default' => 1, 'md' => 2])->columnSpanFull(),
            ])->columns(['default' => 1, 'md' => 2]),
            Section::make('Pengiriman')->schema([
                TextEntry::make('shipping_origin.warehouse_name')->label('Titik pengiriman')->placeholder('Belum tercatat'),
                TextEntry::make('shipping_origin.address')->label('Alamat asal')->placeholder('-'),
                TextEntry::make('shipping_address.name')->label('Penerima'),
                TextEntry::make('shipping_address.phone')->label('Telepon penerima'),
                TextEntry::make('shipping_address.address')->label('Alamat tujuan'),
                TextEntry::make('shipping_address.address_note')->label('Catatan alamat')->placeholder('-'),
                TextEntry::make('shipping_address.province')->label('Provinsi'),
                TextEntry::make('shipping_address.city')->label('Kota'),
                TextEntry::make('shipping_address.district')->label('Kecamatan')->placeholder('-'),
                TextEntry::make('shipping_address.postal_code')->label('Kode pos')->placeholder('-'),
            ])->columns(['default' => 1, 'md' => 2]),
            RepeatableEntry::make('statusHistories')->label('Riwayat status')->schema([
                TextEntry::make('status')->label('Status')->formatStateUsing(fn (string $state): string => StoreTransactionReport::STATUSES[$state] ?? $state),
                TextEntry::make('note')->label('Catatan'),
                TextEntry::make('createdBy.name')->label('Dicatat oleh')->placeholder('Sistem'),
                TextEntry::make('created_at')->label('Waktu (WIB)')->dateTime('d M Y H:i', timezone: 'Asia/Jakarta'),
            ])->columns(['default' => 1, 'md' => 2]),
        ]);
    }

    private static function itemEntries(): array
    {
        return [
            TextEntry::make('product_name')->label('Produk / bundle'),
            TextEntry::make('variant_name')->label('Varian'),
            TextEntry::make('store_name')->label('Toko saat pembelian'),
            TextEntry::make('sku')->label('SKU'),
            TextEntry::make('quantity')->label('Jumlah'),
            TextEntry::make('unit_price')->label('Harga satuan')->money('IDR'),
            TextEntry::make('subtotal')->label('Subtotal')->money('IDR'),
            RepeatableEntry::make('bundle_components')->label('Komponen bundle')->schema([
                TextEntry::make('product_name')->label('Produk'), TextEntry::make('variant_name')->label('Varian'),
                TextEntry::make('sku')->label('SKU'), TextEntry::make('quantity')->label('Jumlah per paket'),
            ])->visible(fn ($state): bool => filled($state))->columnSpanFull(),
        ];
    }

    public static function getPages(): array
    {
        return ['index' => ManageStoreTransactions::route('/')];
    }
}
