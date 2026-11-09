<?php

use App\Jobs\ProcessMidtransNotification;
use App\Jobs\ReconcileOrderPayment;
use App\Models\Order;
use App\Models\PaymentNotification;
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
