@extends('seller.layout', ['title' => 'Pendaftaran Seller Ditutup | Walatra Herbal'])

@section('content')
    <div class="mx-auto max-w-xl rounded-3xl bg-[#fffdf8] p-8 text-center shadow-xl shadow-[#1d4d3a]/5 sm:p-12">
        <h1 class="font-serif text-4xl text-[#123628]">Pendaftaran seller sedang ditutup.</h1>
        <p class="mt-4 text-sm leading-7 text-[#68776d]">Silakan kembali lagi nanti untuk mendaftarkan akun dan toko Anda.</p>
        <a href="{{ url('/') }}" class="mt-8 inline-flex rounded-full bg-[#1d4d3a] px-6 py-3 text-sm font-bold text-white">Kembali ke halaman utama</a>
    </div>
@endsection
