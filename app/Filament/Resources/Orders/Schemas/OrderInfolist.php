<?php

namespace App\Filament\Resources\Orders\Schemas;

use App\Filament\Resources\Orders\Pages\ManageOrders;
use App\Models\Order;
use App\Services\Marketplace\StoreTransactionReport;
use App\Support\PaymentMethods;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class OrderInfolist
{
    public static function configure(Schema $schema): Schema
    {
        $record = $schema->getRecord();
        if ($record instanceof Order) {
            StoreTransactionReport::loadDetails($record);
        }

        return $schema->components([
            Section::make('Order dan customer')->columnSpanFull()->schema([
                TextEntry::make('order_number')->label('Nomor order'),
                TextEntry::make('status')->label('Status order')->formatStateUsing(fn (string $state): string => StoreTransactionReport::STATUSES[$state] ?? $state),
                TextEntry::make('user.name')->label('Customer')->placeholder('Belum melengkapi nama'),
                TextEntry::make('user.phone')->label('WhatsApp'),
                TextEntry::make('placed_at')->label('Diajukan (WIB)')->dateTime('d M Y H:i', timezone: 'Asia/Jakarta')->placeholder('-'),
                TextEntry::make('created_at')->label('Dibuat (WIB)')->dateTime('d M Y H:i', timezone: 'Asia/Jakarta'),
                TextEntry::make('paid_at')->label('Lunas (WIB)')->dateTime('d M Y H:i', timezone: 'Asia/Jakarta')->placeholder('-'),
                TextEntry::make('subtotal')->label('Subtotal seluruh barang')->money('IDR'),
                TextEntry::make('shipping_gross_fee')->label('Ongkir kotor')->money('IDR'),
                TextEntry::make('shipping_subsidy')->label('Subsidi ongkir')->money('IDR'),
                TextEntry::make('shipping_fee')->label('Ongkir bersih')->money('IDR'),
                TextEntry::make('total')->label('Total pembayaran seluruh order')->money('IDR'),
            ])->columns(['default' => 1, 'md' => 2]),
            Section::make('Barang toko terpilih')->columnSpanFull()->visible(fn (ManageOrders $livewire): bool => $livewire->selectedStoreId() !== null)->schema([
                TextEntry::make('selected_store_subtotal')->label('Subtotal barang toko')->money('IDR')
                    ->state(fn (Order $record, ManageOrders $livewire) => StoreTransactionReport::itemsForStore($record->items()->getQuery(), $livewire->selectedStoreId())->sum('subtotal')),
                RepeatableEntry::make('selected_store_items')->label('Barang')->state(fn (Order $record, ManageOrders $livewire) => StoreTransactionReport::selectedItems($record, $livewire->selectedStoreId())->all())
                    ->schema(self::itemEntries())->columns(['default' => 1, 'md' => 2])->columnSpanFull(),
            ]),
            RepeatableEntry::make('items')->label('Seluruh barang order')->schema(self::itemEntries())->columns(['default' => 1, 'md' => 2])->columnSpanFull(),
            Section::make('Pembayaran')->columnSpanFull()->schema([
                TextEntry::make('payment_provider')->label('Sumber pembayaran')->formatStateUsing(fn (string $state): string => StoreTransactionReport::providerLabel($state)),
                TextEntry::make('environment')->label('Lingkungan')->state(fn (Order $record): string => StoreTransactionReport::environmentLabel(StoreTransactionReport::environment($record))),
                TextEntry::make('payment_reference')->label('Referensi pembayaran')->placeholder('-'),
                TextEntry::make('payment_due_at')->label('Batas pembayaran (WIB)')->dateTime('d M Y H:i', timezone: 'Asia/Jakarta')->placeholder('-'),
                TextEntry::make('payment_last_synced_at')->label('Sinkronisasi terakhir (WIB)')->dateTime('d M Y H:i', timezone: 'Asia/Jakarta')->placeholder('Belum disinkronkan'),
                RepeatableEntry::make('payments')->label('Seluruh percobaan pembayaran')->schema([
                    TextEntry::make('environment')->label('Lingkungan')->formatStateUsing(StoreTransactionReport::environmentLabel(...)),
                    TextEntry::make('method')->label('Metode')->formatStateUsing(fn (string $state): string => PaymentMethods::LABELS[$state] ?? $state),
                    TextEntry::make('state')->label('Status percobaan'),
                    TextEntry::make('transaction_status')->label('Status Midtrans')->placeholder('-'),
                    TextEntry::make('provider_order_id')->label('Referensi transaksi'),
                    TextEntry::make('transaction_id')->label('ID Midtrans')->placeholder('-'),
                    TextEntry::make('amount')->label('Nominal seluruh order')->money('IDR'),
                    TextEntry::make('expires_at')->label('Berlaku sampai (WIB)')->dateTime('d M Y H:i', timezone: 'Asia/Jakarta')->placeholder('-'),
                    TextEntry::make('response_payload.va_numbers.0.va_number')->label('Nomor VA')->placeholder('-'),
                    TextEntry::make('response_payload.permata_va_number')->label('VA Permata')->placeholder('-'),
                    TextEntry::make('response_payload.bill_key')->label('Mandiri bill key')->placeholder('-'),
                    TextEntry::make('response_payload.biller_code')->label('Mandiri biller code')->placeholder('-'),
                    TextEntry::make('paid_at')->label('Lunas (WIB)')->dateTime('d M Y H:i', timezone: 'Asia/Jakarta')->placeholder('-'),
                    TextEntry::make('last_synced_at')->label('Disinkronkan (WIB)')->dateTime('d M Y H:i', timezone: 'Asia/Jakarta')->placeholder('-'),
                ])->columns(['default' => 1, 'md' => 2])->columnSpanFull(),
            ])->columns(['default' => 1, 'md' => 2]),
            Section::make('Pengiriman')->columnSpanFull()->schema([
                TextEntry::make('shipping_origin.warehouse_name')->label('Titik pengiriman')->placeholder('Belum tercatat'),
                TextEntry::make('shipping_origin.warehouse_code')->label('Kode titik pengiriman')->placeholder('-'),
                TextEntry::make('shipping_origin.address')->label('Alamat asal')->placeholder('-'),
                TextEntry::make('shipping_origin.postal_code')->label('Kode pos asal')->placeholder('-'),
                TextEntry::make('shipping_origin.coordinates')->label('Koordinat asal')->placeholder('-'),
                TextEntry::make('stock_mode')->label('Mode stok')->placeholder('Belum tercatat'),
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
            ])->columns(['default' => 1, 'md' => 2])->columnSpanFull(),
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
}
