@extends('seller.layout', ['title' => 'Status Pengajuan Toko | Walatra Herbal'])

@section('content')
    <div class="mx-auto max-w-2xl rounded-3xl bg-[#fffdf8] p-6 shadow-xl shadow-[#1d4d3a]/5 sm:p-10">
        <div class="flex h-14 w-14 items-center justify-center rounded-2xl bg-[#e5f0e4] text-2xl text-[#1d4d3a]">✓</div>
        <p class="mt-7 text-sm font-bold uppercase tracking-[.14em] text-[#c59445]">Pengajuan diterima</p>
        <h1 class="mt-3 font-serif text-4xl leading-tight tracking-tight text-[#123628]">Toko sedang diperiksa.</h1>
        <p class="mt-4 text-sm leading-7 text-[#68776d]">Data toko sudah tersimpan dan menunggu verifikasi email serta persetujuan Superadmin. Seller belum dapat mengelola etalase sebelum toko disetujui.</p>
        <dl class="mt-8 divide-y divide-[#1d4d3a]/10 rounded-2xl border border-[#1d4d3a]/10"><div class="flex justify-between gap-5 p-4 text-sm"><dt class="text-[#68776d]">Nama toko</dt><dd class="text-right font-bold text-[#173127]">{{ $store->name }}</dd></div><div class="flex justify-between gap-5 p-4 text-sm"><dt class="text-[#68776d]">Kode toko</dt><dd class="text-right font-bold text-[#173127]">{{ $store->code }}</dd></div><div class="flex justify-between gap-5 p-4 text-sm"><dt class="text-[#68776d]">Status toko</dt><dd class="text-right font-bold text-amber-700">Menunggu persetujuan</dd></div><div class="flex justify-between gap-5 p-4 text-sm"><dt class="text-[#68776d]">Email</dt><dd class="text-right font-bold {{ $store->owner?->email_verified_at ? 'text-emerald-700' : 'text-amber-700' }}">{{ $store->owner?->email_verified_at ? 'Terverifikasi' : 'Menunggu verifikasi' }}</dd></div></dl>
        <a href="{{ url('/') }}" class="mt-8 inline-flex rounded-full bg-[#1d4d3a] px-6 py-3 text-sm font-bold text-white transition hover:-translate-y-0.5 hover:bg-[#123628]">Kembali ke halaman utama</a>
    </div>
@endsection
