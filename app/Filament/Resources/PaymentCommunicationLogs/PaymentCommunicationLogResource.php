<?php

namespace App\Filament\Resources\PaymentCommunicationLogs;

use App\Filament\Resources\PaymentCommunicationLogs\Pages\ListPaymentCommunicationLogs;
use App\Filament\Resources\PaymentCommunicationLogs\Schemas\PaymentAuditEntries;
use App\Models\PaymentCommunicationLog;
use App\Support\PaymentMethods;
use App\Support\PaymentPayloadSanitizer;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\CodeEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class PaymentCommunicationLogResource extends Resource
{
    protected static ?string $model = PaymentCommunicationLog::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCodeBracket;

    protected static string|UnitEnum|null $navigationGroup = 'Manajemen Marketplace';

    protected static ?int $navigationSort = 5;

    protected static ?string $navigationLabel = 'Log Pembayaran';

    protected static ?string $modelLabel = 'log pembayaran';

    protected static ?string $pluralModelLabel = 'Log Pembayaran';

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
        return parent::getEloquentQuery()->select(['id', 'order_id', 'order_payment_id', 'provider', 'environment',
            'provider_order_id', 'operation', 'http_method', 'http_status', 'outcome', 'requested_at', 'duration_ms'])
            ->with('order:id,order_number');
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('id', 'desc')->columns([
            TextColumn::make('row_number')->label('No.')->rowIndex()->alignCenter(),
            TextColumn::make('requested_at')->label('Waktu (WIB)')->dateTime('d M Y H:i:s', timezone: 'Asia/Jakarta')->sortable(),
            TextColumn::make('operation')->label('Operasi')->badge(),
            TextColumn::make('order.order_number')->label('Order')->placeholder('Tanpa order Laravel')->searchable(),
            TextColumn::make('provider_order_id')->label('Referensi Midtrans')->searchable()->wrap(),
            TextColumn::make('environment')->label('Lingkungan')->badge(),
            TextColumn::make('http_status')->label('HTTP')->placeholder('-'),
            TextColumn::make('outcome')->label('Hasil komunikasi')->badge(),
            TextColumn::make('duration_ms')->label('Durasi')->suffix(' ms')->placeholder('-'),
        ])->filters([
            SelectFilter::make('environment')->label('Lingkungan')->options(['sandbox' => 'Sandbox', 'production' => 'Production']),
            SelectFilter::make('operation')->label('Operasi')->options(['charge' => 'Charge', 'status' => 'Cek status', 'cancel' => 'Pembatalan']),
            SelectFilter::make('outcome')->label('Hasil')->options(['started' => 'Belum selesai', 'succeeded' => 'Berhasil', 'not_found' => 'Transaksi tidak ditemukan', 'failed' => 'Respons ditolak', 'connection_error' => 'Gangguan koneksi / request']),
            SelectFilter::make('payment')->label('Percobaan pembayaran')->relationship('payment', 'provider_order_id')->searchable(),
            SelectFilter::make('order')->label('Order')->relationship('order', 'order_number')->searchable(),
        ])->deferFilters(false)->recordActions([ViewAction::make()->label('Detail log')->iconButton()->color('gray')->slideOver()->authorize(fn (): bool => self::canAccess())])
            ->recordActionsColumnLabel('Detail')
            ->toolbarActions([]);
    }

    public static function infolist(Schema $schema): Schema
    {
        $schema->getRecord()?->refresh();

        return $schema->components([
            Section::make('Komunikasi Midtrans')->columnSpanFull()->columns(['default' => 1, 'md' => 2])->schema([
                TextEntry::make('id')->label('ID log'),
                TextEntry::make('order.order_number')->label('Order')->placeholder('Tanpa order Laravel'),
                TextEntry::make('order_payment_id')->label('ID percobaan pembayaran')->placeholder('-'),
                TextEntry::make('provider_order_id')->label('Referensi Midtrans')->placeholder('-'),
                TextEntry::make('transaction_id')->label('ID transaksi Midtrans')
                    ->state(fn ($record) => $record->payment?->transaction_id ?? ($record->response_payload['transaction_id'] ?? null))->placeholder('-'),
                TextEntry::make('environment')->label('Lingkungan'),
                TextEntry::make('method')->label('Metode pembayaran')
                    ->state(fn ($record) => $record->payment?->method ?? ($record->response_payload['payment_type'] ?? $record->request_payload['payment_type'] ?? null))
                    ->formatStateUsing(fn (string $state): string => PaymentMethods::LABELS[$state] ?? $state)->placeholder('-'),
                TextEntry::make('amount')->label('Nominal pembayaran')
                    ->state(fn ($record) => $record->payment?->amount ?? ($record->response_payload['gross_amount'] ?? $record->request_payload['transaction_details']['gross_amount'] ?? null))
                    ->money('IDR')->placeholder('-'),
                TextEntry::make('payment.last_synced_at')->label('Sinkronisasi pembayaran (WIB)')->dateTime('d M Y H:i:s', timezone: 'Asia/Jakarta')->placeholder('-'),
                TextEntry::make('operation')->label('Operasi'),
                TextEntry::make('http_method')->label('HTTP method'),
                TextEntry::make('endpoint')->label('Endpoint')->columnSpanFull(),
                TextEntry::make('http_status')->label('HTTP status')->placeholder('Tidak ada respons HTTP'),
                TextEntry::make('outcome')->label('Hasil komunikasi'),
                TextEntry::make('requested_at')->label('Request (WIB)')->dateTime('d M Y H:i:s', timezone: 'Asia/Jakarta'),
                TextEntry::make('finished_at')->label('Selesai (WIB)')->dateTime('d M Y H:i:s', timezone: 'Asia/Jakarta')->placeholder('Belum selesai'),
                TextEntry::make('duration_ms')->label('Durasi')->suffix(' ms')->placeholder('-'),
                TextEntry::make('error_type')->label('Jenis kegagalan')->placeholder('-'),
                TextEntry::make('error_message')->label('Keterangan kegagalan')->placeholder('-')->columnSpanFull(),
            ]),
            Section::make('Request dan respons')->description('Data tersimpan untuk panggilan ini; kredensial disaring. Berhasil berarti komunikasi diterima, bukan pembayaran sudah lunas.')
                ->columnSpanFull()->schema([
                    ...PaymentAuditEntries::payloads(),
                    CodeEntry::make('response_body')->label('Respons non-JSON')
                        ->state(fn ($record) => $record->response_body === null ? null : ['body' => PaymentPayloadSanitizer::sanitize($record->response_body)])
                        ->placeholder('Tidak ada respons non-JSON.')->columnSpanFull(),
                ]),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListPaymentCommunicationLogs::route('/')];
    }
}
