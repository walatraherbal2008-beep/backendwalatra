<?php

namespace App\Filament\Resources\Orders;

use App\Filament\Resources\Orders\Pages\ManageOrders;
use App\Models\Order;
use App\Services\Marketplace\ManualOrderService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class OrderResource extends Resource
{
    protected static ?string $model = Order::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static string|UnitEnum|null $navigationGroup = 'Manajemen Marketplace';

    protected static ?int $navigationSort = 4;

    protected static ?string $navigationLabel = 'Pesanan';

    protected static ?string $modelLabel = 'pesanan';

    protected static ?string $pluralModelLabel = 'pesanan';

    public static function canAccess(): bool
    {
        return auth()->user()?->status === 'active' && auth()->user()->hasRole('super_admin');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('payment_provider', 'manual')->with(['user', 'items', 'statusHistories.createdBy']);
    }

    private static function statusLabel(string $status): string
    {
        return match ($status) {
            'pending_payment' => 'Menunggu pembayaran',
            'paid' => 'Lunas',
            'cancelled' => 'Dibatalkan',
            default => $status,
        };
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Pesanan dan pembayaran')->schema([
                TextEntry::make('order_number')->label('Nomor pesanan'),
                TextEntry::make('status')->label('Status')->formatStateUsing(fn (string $state): string => self::statusLabel($state)),
                TextEntry::make('user.name')->label('Customer')->placeholder('Belum melengkapi nama'),
                TextEntry::make('user.phone')->label('WhatsApp'),
                TextEntry::make('subtotal')->label('Subtotal')->money('IDR'),
                TextEntry::make('shipping_gross_fee')->label('Ongkir kotor')->money('IDR'),
                TextEntry::make('shipping_subsidy')->label('Subsidi ongkir')->money('IDR'),
                TextEntry::make('shipping_fee')->label('Ongkir bersih')->money('IDR'),
                TextEntry::make('total')->label('Total pembayaran')->money('IDR'),
                TextEntry::make('payment_reference')->label('Referensi pembayaran')->placeholder('-'),
                TextEntry::make('placed_at')->label('Dibuat')->dateTime('d M Y H:i'),
                TextEntry::make('paid_at')->label('Dikonfirmasi lunas')->dateTime('d M Y H:i')->placeholder('Belum dikonfirmasi'),
            ])->columns(2),
            Section::make('Alamat pengiriman')->schema([
                TextEntry::make('shipping_address.name')->label('Penerima'),
                TextEntry::make('shipping_address.phone')->label('Telepon penerima'),
                TextEntry::make('shipping_address.address')->label('Alamat'),
                TextEntry::make('shipping_address.address_note')->label('Catatan alamat')->placeholder('-'),
                TextEntry::make('shipping_address.province')->label('Provinsi'),
                TextEntry::make('shipping_address.city')->label('Kota'),
                TextEntry::make('shipping_address.district')->label('Kecamatan')->placeholder('-'),
                TextEntry::make('shipping_address.postal_code')->label('Kode pos'),
            ])->columns(2),
            RepeatableEntry::make('items')->label('Barang pesanan')->schema([
                TextEntry::make('product_name')->label('Produk / bundle'),
                TextEntry::make('variant_name')->label('Varian'),
                TextEntry::make('sku')->label('SKU'),
                TextEntry::make('store_name')->label('Toko'),
                TextEntry::make('quantity')->label('Jumlah'),
                TextEntry::make('unit_price')->label('Harga satuan')->money('IDR'),
                TextEntry::make('subtotal')->label('Subtotal')->money('IDR'),
                RepeatableEntry::make('bundle_components')->label('Komponen bundle')->schema([
                    TextEntry::make('product_name')->label('Produk'),
                    TextEntry::make('variant_name')->label('Varian'),
                    TextEntry::make('sku')->label('SKU'),
                    TextEntry::make('quantity')->label('Jumlah per paket'),
                ])->columns(2)->visible(fn ($state): bool => filled($state)),
            ])->columns(2),
            RepeatableEntry::make('statusHistories')->label('Riwayat perubahan')->schema([
                TextEntry::make('status')->label('Status')->formatStateUsing(fn (string $state): string => self::statusLabel($state)),
                TextEntry::make('note')->label('Catatan'),
                TextEntry::make('createdBy.name')->label('Dicatat oleh')->placeholder('Sistem'),
                TextEntry::make('created_at')->label('Waktu')->dateTime('d M Y H:i'),
            ])->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('order_number')->label('Nomor pesanan')->searchable(),
            TextColumn::make('user.name')->label('Customer')->searchable()->wrap()->placeholder('Belum melengkapi nama'),
            TextColumn::make('user.phone')->label('WhatsApp')->searchable()->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('status')->label('Status')->badge()
                ->formatStateUsing(fn (string $state): string => self::statusLabel($state))
                ->color(fn (string $state): string => match ($state) {
                    'paid' => 'success', 'cancelled' => 'danger', default => 'warning',
                }),
            TextColumn::make('total')->label('Total')->money('IDR')->sortable(),
            TextColumn::make('placed_at')->label('Dibuat')->dateTime('d M Y H:i')->sortable(),
            TextColumn::make('paid_at')->label('Lunas pada')->dateTime('d M Y H:i')->placeholder('-')->toggleable(isToggledHiddenByDefault: true),
        ])->filters([
            SelectFilter::make('status')->label('Status')->options([
                'pending_payment' => 'Menunggu pembayaran', 'paid' => 'Lunas', 'cancelled' => 'Dibatalkan',
            ]),
        ])->recordActions([
            ActionGroup::make([
                ViewAction::make()->label('Detail')->schema(fn (Schema $schema): Schema => self::infolist($schema)),
                Action::make('confirmPaid')->label('Konfirmasi lunas')->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('Konfirmasi pembayaran pesanan')
                    ->modalDescription('Pastikan stok tersedia dan dana pembayaran sudah diterima sesuai total pesanan sebelum mengonfirmasi.')
                    ->schema([
                        TextInput::make('reference')->label('Referensi pembayaran')->maxLength(255),
                        Textarea::make('note')->label('Catatan verifikasi')->required()->minLength(5)->maxLength(1000),
                    ])
                    ->visible(fn (Order $record): bool => $record->status === 'pending_payment')
                    ->action(function (Order $record, array $data): void {
                        app(ManualOrderService::class)->confirmPaid($record, auth()->user(), $data['note'], $data['reference'] ?? null);
                        Notification::make()->title('Pembayaran berhasil dikonfirmasi')->success()->send();
                    }),
                Action::make('cancelOrder')->label('Batalkan')->color('danger')->requiresConfirmation()
                    ->modalHeading('Batalkan pesanan')
                    ->schema([Textarea::make('note')->label('Alasan pembatalan')->required()->minLength(5)->maxLength(1000)])
                    ->visible(fn (Order $record): bool => $record->status === 'pending_payment')
                    ->action(function (Order $record, array $data): void {
                        app(ManualOrderService::class)->cancel($record, auth()->user(), $data['note']);
                        Notification::make()->title('Pesanan dibatalkan')->success()->send();
                    }),
            ])->label('Tindakan')->button(),
        ])->defaultSort('created_at', 'desc')
            ->emptyStateHeading('Belum ada pesanan manual')
            ->emptyStateDescription('Pesanan customer akan muncul di sini setelah checkout dengan metode pembayaran manual.');
    }

    public static function getPages(): array
    {
        return ['index' => ManageOrders::route('/')];
    }
}
