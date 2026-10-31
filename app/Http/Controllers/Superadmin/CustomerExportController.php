<?php

namespace App\Http\Controllers\Superadmin;

use App\Models\User;

class CustomerExportController
{
    public function __invoke()
    {
        abort_unless(auth()->user()?->hasRole('super_admin'), 403);

        return response()->streamDownload(function (): void {
            $output = fopen('php://output', 'w');
            fputcsv($output, ['ID', 'Nama', 'WhatsApp', 'Email', 'Status', 'Jumlah Alamat', 'Jumlah Pesanan', 'Terdaftar', 'Login Terakhir']);

            User::query()
                ->where('user_type', 'customer')
                ->withCount(['addresses', 'orders'])
                ->orderBy('id')
                ->chunk(500, function ($customers) use ($output): void {
                    foreach ($customers as $customer) {
                        fputcsv($output, [
                            $customer->id,
                            $customer->name,
                            $customer->phone,
                            $customer->email,
                            $customer->status,
                            $customer->addresses_count,
                            $customer->orders_count,
                            $customer->created_at?->toIso8601String(),
                            $customer->last_login_at?->toIso8601String(),
                        ]);
                    }
                });

            fclose($output);
        }, 'customer-'.now()->format('Ymd-His').'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}
