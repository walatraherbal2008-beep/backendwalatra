<?php

use App\Jobs\ProcessMidtransNotification;
use App\Jobs\ReconcileOrderPayment;
use App\Models\ExternalApiActivityLog;
use App\Models\Order;
use App\Models\PaymentNotification;
use App\Services\ExternalApiActivityLogger;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('payments:reconcile {--limit=50}', function () {
    $limit = max(1, min(200, (int) $this->option('limit')));
    $notifications = PaymentNotification::whereNull('processed_at')->orderBy('updated_at')->limit($limit)->get();
    foreach ($notifications as $notification) {
        ProcessMidtransNotification::dispatch($notification->id);
        $notification->touch();
    }
    $orders = Order::where('payment_provider', 'midtrans')
        ->where(function ($query) {
            $query->whereIn('status', ['pending_payment', 'payment_review'])
                ->orWhere(fn ($query) => $query->where('status', 'paid')->where('paid_at', '>=', now()->subDays(2)));
        })->where(fn ($query) => $query->whereNull('payment_last_synced_at')->orWhere('payment_last_synced_at', '<=', now()->subMinute()))
        ->orderByRaw('CASE WHEN payment_last_synced_at IS NULL THEN 0 ELSE 1 END')->orderBy('payment_last_synced_at')->limit($limit)->get();
    foreach ($orders as $order) {
        ReconcileOrderPayment::dispatch($order->id);
    }
    $this->info('Sinkronisasi pembayaran dijadwalkan.');
})->purpose('Pulihkan inbox webhook dan sinkronkan status pembayaran Midtrans');

Schedule::command('payments:reconcile')->everyMinute()->withoutOverlapping();
Schedule::command('warehouses:sync')->hourly()->withoutOverlapping()
    ->when(fn (): bool => (bool) config('services.warehouse_directory.enabled'));
Schedule::command('warehouses:sync-stock')->hourly()->withoutOverlapping()
    ->when(fn (): bool => (bool) config('services.warehouse_remaining_stock.enabled'));

Artisan::command('logs:prune-external {--days=} {--dry-run}', function () {
    $days = $this->option('days') ?? config('security.external_log_retention_days', 30);
    if (filter_var($days, FILTER_VALIDATE_INT) === false || (int) $days < 1 || (int) $days > 3650) {
        $this->error('Retensi log harus antara 1 dan 3650 hari.');

        return 1;
    }
    $query = ExternalApiActivityLog::where('started_at', '<', now()->subDays((int) $days));
    if ($this->option('dry-run')) {
        $this->info($query->count().' log melewati masa retensi.');

        return 0;
    }
    $deleted = 0;
    do {
        $ids = (clone $query)->orderBy('id')->limit(1000)->pluck('id');
        $deleted += ExternalApiActivityLog::whereIn('id', $ids)->delete();
    } while ($ids->count() === 1000);
    $this->info($deleted.' log kedaluwarsa dihapus.');

    return 0;
})->purpose('Hapus log API eksternal yang melewati masa retensi');

Schedule::command('logs:prune-external')->daily()->withoutOverlapping();

Artisan::command('logs:sanitize-external {--dry-run}', function () {
    $logger = app(ExternalApiActivityLogger::class);
    $changed = 0;
    ExternalApiActivityLog::query()->chunkById(500, function ($logs) use ($logger, &$changed): void {
        foreach ($logs as $log) {
            $log->fill([
                'request_payload' => $logger->sanitizePayload($log->request_payload),
                'response_payload' => $logger->sanitizePayload($log->response_payload),
                'endpoint' => $logger->sanitizeText($log->endpoint) ?? '[EMPTY]',
                'error_message' => $logger->sanitizeText($log->error_message),
            ]);
            if ($log->isDirty()) {
                $changed++;
                if (! $this->option('dry-run')) {
                    $log->save();
                }
            }
        }
    });
    $this->info($changed.' log '.($this->option('dry-run') ? 'memerlukan sanitasi.' : 'disanitasi.'));

    return 0;
})->purpose('Samarkan data sensitif pada log API eksternal historis');
