<?php

namespace App\Filament\Resources\Orders\Tables;

use App\Filament\Resources\Orders\Actions\OrderActions;
use App\Filament\Resources\Orders\Pages\ManageOrders;
use App\Filament\Resources\Orders\Schemas\OrderInfolist;
use App\Filament\Resources\PaymentCommunicationLogs\PaymentCommunicationLogResource;
use App\Models\Order;
use App\Models\Store;
use App\Services\Marketplace\StoreTransactionReport;
use App\Support\PaymentMethods;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class OrdersTable
{
    public static function configure(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('row_number')->label('No.')->rowIndex()->alignCenter(),
            TextColumn::make('order_number')->label('Pesanan / customer')->searchable()->copyable()->weight('semibold')->wrap()
                ->description(fn (Order $record): string => ($record->user?->name ?? 'Belum melengkapi nama').' · '.$record->created_at->timezone('Asia/Jakarta')->format('d M Y H:i').' WIB'),
            TextColumn::make('user.name')->label('Customer')->searchable()->placeholder('Belum melengkapi nama')->wrap()->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('user.phone')->label('WhatsApp')->searchable()->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('stores')->label('Toko saat pembelian')
                ->state(fn (Order $record): array => $record->items->pluck('store_name')->filter()->unique()->values()->all())->wrap()
                ->listWithLineBreaks()->limitList(3)->expandableLimitedList()->placeholder('Belum tercatat'),
            TextColumn::make('payment_provider')->label('Sumber pembayaran')->badge()
                ->formatStateUsing(fn (string $state): string => StoreTransactionReport::providerLabel($state))->color('gray')
                ->description(fn (Order $record): ?string => ! in_array($record->payment_provider, ['manual', 'simulation'], true)
                    ? StoreTransactionReport::environmentLabel(StoreTransactionReport::environment($record)) : null),
            TextColumn::make('activePayment.method')->label('Metode')->toggleable(isToggledHiddenByDefault: true)
                ->formatStateUsing(fn (string $state): string => PaymentMethods::LABELS[$state] ?? $state)->placeholder('-'),
            TextColumn::make('environment')->label('Lingkungan')->toggleable(isToggledHiddenByDefault: true)->state(fn (Order $record): string => StoreTransactionReport::environment($record))
                ->formatStateUsing(StoreTransactionReport::environmentLabel(...))->badge()
                ->color(fn (string $state): string => match ($state) {
                    'production' => 'success', 'sandbox' => 'warning', default => 'gray'
                }),
            TextColumn::make('status')->label('Status order')->badge()->wrap()
                ->formatStateUsing(fn (string $state): string => StoreTransactionReport::STATUSES[$state] ?? $state)
                ->color(fn (string $state): string => match ($state) {
                    'paid' => 'success', 'payment_review' => 'danger', 'pending_payment' => 'warning', default => 'gray'
                }),
            TextColumn::make('store_subtotal')->label('Subtotal barang toko')->money('IDR')
                ->visible(fn (ManageOrders $livewire): bool => $livewire->selectedStoreId() !== null),
            TextColumn::make('total')->label('Total pembayaran order')->money('IDR')->sortable(),
            TextColumn::make('created_at')->label('Dibuat (WIB)')->dateTime('d M Y H:i', timezone: 'Asia/Jakarta')->sortable()->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('paid_at')->label('Lunas (WIB)')->dateTime('d M Y H:i', timezone: 'Asia/Jakarta')->placeholder('-')->sortable()->toggleable(isToggledHiddenByDefault: true),
        ])->filters([
            SelectFilter::make('store')->label('Toko')->searchable()
                ->options(fn (): array => Store::query()->orderBy('name')->get()->mapWithKeys(fn (Store $store): array => [$store->id => $store->name.' ('.$store->code.')'])->all())
                ->query(fn (Builder $query): Builder => $query)
                ->baseQuery(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
                    ? StoreTransactionReport::filterStore($query, (int) $data['value']) : $query),
            SelectFilter::make('status')->label('Status order')->options(fn (): array => StoreTransactionReport::statusOptions())->excludeWhenResolvingRecord(),
            SelectFilter::make('payment_provider')->label('Sumber pembayaran')->options(fn (): array => StoreTransactionReport::providerOptions())->excludeWhenResolvingRecord(),
            SelectFilter::make('environment')->label('Lingkungan')
                ->excludeWhenResolvingRecord()
                ->options(['sandbox' => 'Sandbox', 'production' => 'Production', 'manual' => 'Manual', 'simulation' => 'Simulasi', 'unknown' => 'Belum diketahui'])
                ->query(fn (Builder $query, array $data): Builder => StoreTransactionReport::filterEnvironment($query, $data['value'] ?? null)),
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
                ->schema(fn (Schema $schema): Schema => OrderInfolist::configure($schema))
                ->extraModalFooterActions(OrderActions::operational()),
            ActionGroup::make([
                Action::make('viewPaymentLogs')->label('Lihat log pesanan')->icon(Heroicon::OutlinedCodeBracket)
                    ->authorize(fn (): bool => PaymentCommunicationLogResource::canAccess())
                    ->url(fn (Order $record): string => PaymentCommunicationLogResource::getUrl('index', ['filters' => ['order' => ['value' => $record->id]]])),
                ...OrderActions::operational(),
            ])->label('Tindakan')->iconButton()->color('gray'),
        ])->recordActionsColumnLabel('Tindakan')
            ->defaultSort(fn (Builder $query): Builder => $query->orderByDesc('orders.created_at')->orderByDesc('orders.id'))->recordAction('view')->paginationPageOptions([10, 25, 50])
            ->emptyStateHeading('Belum ada pesanan sesuai filter')
            ->emptyStateDescription('Semua sumber pesanan ditampilkan. Data diperbarui otomatis setiap 5 detik.');
    }
}
