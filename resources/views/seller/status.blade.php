@extends('seller.layout', ['title' => 'Status Pengajuan Toko | Walatra Herbal'])

@section('content')
    @php
        [$label, $heading, $description, $statusColor] = match ($store->status) {
            'active' => ['Aktif', 'Toko Anda sudah disetujui.', 'Toko sudah aktif. Buka panel toko untuk mengelola etalase dan operasional toko Anda.', 'text-emerald-700'],
            'rejected' => ['Ditolak', 'Pengajuan toko ditolak.', 'Pengajuan toko belum disetujui. Hubungi tim Walatra untuk informasi lebih lanjut.', 'text-red-700'],
            'suspended' => ['Ditangguhkan', 'Toko Anda ditangguhkan.', 'Operasional toko sedang ditangguhkan. Hubungi tim Walatra untuk informasi lebih lanjut.', 'text-amber-700'],
            'inactive' => ['Tidak aktif', 'Toko Anda tidak aktif.', 'Toko saat ini tidak aktif. Hubungi tim Walatra untuk informasi pengaktifan kembali.', 'text-slate-700'],
            'pending_owner' => ['Menunggu pemilik member', 'Toko menunggu penetapan pemilik.', 'Pemilik toko perlu ditetapkan sebelum toko dapat beroperasi. Hubungi tim Walatra untuk melanjutkan proses.', 'text-amber-700'],
            'pending_approval' => ['Menunggu persetujuan', 'Toko sedang diperiksa.', $store->owner?->email_verified_at
                ? 'Email sudah terverifikasi. Pengajuan toko menunggu pemeriksaan dan persetujuan Superadmin.'
                : 'Data toko sudah tersimpan. Verifikasi email Anda dan tunggu persetujuan Superadmin sebelum mengelola etalase.', 'text-amber-700'],
            default => ['Status belum tersedia', 'Status toko belum tersedia.', 'Hubungi tim Walatra untuk memeriksa status toko Anda.', 'text-slate-700'],
        };
    @endphp
    <div class="mx-auto max-w-2xl rounded-3xl bg-[#fffdf8] p-6 shadow-xl shadow-[#1d4d3a]/5 sm:p-10">
        <p class="text-sm font-bold uppercase tracking-[.14em] text-[#86601e]">Status toko</p>
        <h1 class="mt-3 font-serif text-4xl leading-tight tracking-tight text-[#123628]">{{ $heading }}</h1>
        <p class="mt-4 text-sm leading-7 text-[#56665c]">{{ $description }}</p>
        <dl class="mt-8 divide-y divide-[#1d4d3a]/10 rounded-2xl border border-[#1d4d3a]/10">
            <div class="flex justify-between gap-5 p-4 text-sm"><dt class="text-[#56665c]">Nama toko</dt><dd class="text-right font-bold text-[#173127]">{{ $store->name }}</dd></div>
            <div class="flex justify-between gap-5 p-4 text-sm"><dt class="text-[#56665c]">Kode toko</dt><dd class="text-right font-bold text-[#173127]">{{ $store->code }}</dd></div>
            <div class="flex justify-between gap-5 p-4 text-sm"><dt class="text-[#56665c]">Status toko</dt><dd class="text-right font-bold {{ $statusColor }}">{{ $label }}</dd></div>
            <div class="flex justify-between gap-5 p-4 text-sm"><dt class="text-[#56665c]">Email</dt><dd class="text-right font-bold {{ $store->owner?->email_verified_at ? 'text-emerald-700' : 'text-amber-700' }}">{{ $store->owner?->email_verified_at ? 'Terverifikasi' : 'Menunggu verifikasi' }}</dd></div>
        </dl>
        @if($store->status === 'active')
            <a href="{{ url('/admin') }}" class="mt-8 inline-flex min-h-12 items-center rounded-full bg-[#1d4d3a] px-6 py-3 text-sm font-bold text-white transition hover:bg-[#123628]">Buka panel toko</a>
        @else
            <a href="{{ url('/') }}" class="mt-8 inline-flex min-h-12 items-center rounded-full bg-[#1d4d3a] px-6 py-3 text-sm font-bold text-white transition hover:bg-[#123628]">Kembali ke halaman utama</a>
        @endif
    </div>
@endsection
