<?php

namespace App\Filament\Resources\Orders\Actions;

use App\Exceptions\PaymentGatewayUnavailableException;
use App\Exceptions\PaymentOperationInProgress;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\Orders\Pages\ManageOrders;
use App\Models\Order;
use App\Services\Marketplace\ManualOrderService;
use App\Services\Payments\OrderPayments;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\ValidationException;

class OrderActions
{
    public static function operational(): array
    {
        return [self::syncPayment(), self::confirmPaid(), self::cancelOrder()];
    }

    public static function syncPayment(): Action
    {
        return Action::make('syncPayment')->label('Sinkronkan pembayaran')->icon(Heroicon::OutlinedArrowPath)
            ->visible(fn (Order $record): bool => $record->payment_provider === 'midtrans')
            ->action(function (Order $record, ManageOrders $livewire): void {
                abort_unless(OrderResource::canAccess() && $record->payment_provider === 'midtrans', 403);
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
                self::reload($record, $livewire);
            });
    }

    public static function confirmPaid(): Action
    {
        return Action::make('confirmPaid')->label('Konfirmasi lunas')->color('success')->requiresConfirmation()
            ->modalHeading('Konfirmasi pembayaran pesanan')
            ->modalDescription('Pastikan stok tersedia dan dana pembayaran sudah diterima sesuai total pesanan sebelum mengonfirmasi.')
            ->schema([
                TextInput::make('reference')->label('Referensi pembayaran')->maxLength(255),
                Textarea::make('note')->label('Catatan verifikasi')->required()->minLength(5)->maxLength(1000),
            ])
            ->visible(fn (Order $record): bool => $record->payment_provider === 'manual' && $record->status === 'pending_payment')
            ->action(function (Order $record, array $data, ManageOrders $livewire): void {
                abort_unless(OrderResource::canAccess() && $record->payment_provider === 'manual', 403);
                app(ManualOrderService::class)->confirmPaid($record, auth()->user(), $data['note'], $data['reference'] ?? null);
                self::reload($record, $livewire);
                Notification::make()->title('Pembayaran berhasil dikonfirmasi')->success()->send();
            });
    }

    public static function cancelOrder(): Action
    {
        return Action::make('cancelOrder')->label('Batalkan')->color('danger')->requiresConfirmation()
            ->modalHeading('Batalkan pesanan')
            ->schema([Textarea::make('note')->label('Alasan pembatalan')->required()->minLength(5)->maxLength(1000)])
            ->visible(fn (Order $record): bool => in_array($record->payment_provider, ['manual', 'midtrans'], true) && $record->status === 'pending_payment')
            ->action(function (Order $record, array $data, ManageOrders $livewire): void {
                abort_unless(OrderResource::canAccess() && in_array($record->payment_provider, ['manual', 'midtrans'], true), 403);
                $note = trim($data['note']);
                if (mb_strlen($note) < 5) {
                    throw ValidationException::withMessages(['note' => 'Alasan pembatalan minimal 5 karakter.']);
                }
                try {
                    if ($record->payment_provider === 'midtrans') {
                        app(OrderPayments::class)->cancel($record);
                        $record->statusHistories()->where('status', 'cancelled')->latest('id')->first()?->update([
                            'note' => 'Pembatalan oleh superadmin: '.$note, 'created_by' => auth()->id(),
                        ]);
                    } else {
                        app(ManualOrderService::class)->cancel($record, auth()->user(), $note);
                    }
                    Notification::make()->title('Pesanan dibatalkan')->success()->send();
                } catch (PaymentOperationInProgress) {
                    Notification::make()->title('Pembatalan sedang diproses')->body('Status pembatalan menunggu konfirmasi Midtrans.')->warning()->send();
                } catch (PaymentGatewayUnavailableException) {
                    Notification::make()->title('Pembatalan belum dapat dikonfirmasi')->body('Silakan sinkronkan pembayaran sebelum mencoba kembali.')->danger()->send();
                } catch (HttpResponseException) {
                    Notification::make()->title('Pesanan belum dapat dibatalkan')->body('Periksa kembali status pembayaran terbaru.')->warning()->send();
                }
                self::reload($record, $livewire);
            });
    }

    private static function reload(Order $record, ManageOrders $livewire): void
    {
        $record->refresh()->load([
            'items.productListing' => fn ($query) => $query->withTrashed(),
            'items.bundleListing' => fn ($query) => $query->withTrashed(),
            'statusHistories.createdBy', 'payments', 'activePayment',
        ]);
        $livewire->refreshMonitoring();
    }
}
