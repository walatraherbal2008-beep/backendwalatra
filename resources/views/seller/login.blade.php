@extends('seller.layout', ['title' => 'Login Seller | Walatra Herbal'])

@section('content')
    <div class="mx-auto max-w-lg rounded-3xl bg-[#fffdf8] p-6 shadow-xl shadow-[#1d4d3a]/5 sm:p-10">
        <p class="text-sm font-bold uppercase tracking-[.14em] text-[#c59445]">Akses seller</p>
        <h1 class="mt-3 font-serif text-4xl leading-tight tracking-tight text-[#123628]">Masuk untuk mendaftarkan toko.</h1>
        <p class="mt-4 text-sm leading-7 text-[#68776d]">Gunakan nomor WhatsApp member Walatra. Kami akan mengirimkan kode OTP untuk melanjutkan.</p>

        @if(session('otp_sent'))
            <div class="mt-6 rounded-2xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-800" role="status">{{ session('otp_sent') }}</div>
        @endif

        <form method="POST" action="{{ session('seller_otp.challenge_id') ? route('seller.login.verify-otp') : route('seller.login.request-otp') }}" class="mt-8 space-y-5">
            @csrf
            @if(session('seller_otp.challenge_id'))
                <div>
                    <label for="otp" class="block text-sm font-semibold text-[#173127]">Kode OTP</label>
                    <input id="otp" name="otp" inputmode="numeric" autocomplete="one-time-code" maxlength="6" required class="mt-2 w-full rounded-2xl border border-[#1d4d3a]/15 bg-white px-4 py-3 tracking-[.35em] outline-none transition focus:border-[#1d4d3a] focus:ring-4 focus:ring-[#1d4d3a]/10" value="{{ old('otp') }}">
                    @error('otp')<p class="mt-2 text-sm text-red-700">{{ $message }}</p>@enderror
                    <p class="mt-2 text-xs text-[#68776d]">OTP berlaku selama 5 menit.</p>
                </div>
                <input type="hidden" name="phone" value="{{ session('seller_otp.phone') }}">
                <button class="w-full rounded-full bg-[#1d4d3a] px-5 py-3.5 text-sm font-bold text-white transition hover:-translate-y-0.5 hover:bg-[#123628]" type="submit">Verifikasi OTP</button>
            @else
                <div>
                    <label for="phone" class="block text-sm font-semibold text-[#173127]">Nomor WhatsApp</label>
                    <input id="phone" name="phone" type="tel" autocomplete="tel" required class="mt-2 w-full rounded-2xl border border-[#1d4d3a]/15 bg-white px-4 py-3 outline-none transition focus:border-[#1d4d3a] focus:ring-4 focus:ring-[#1d4d3a]/10" value="{{ old('phone') }}" placeholder="0812xxxxxxxx">
                    @error('phone')<p class="mt-2 text-sm text-red-700">{{ $message }}</p>@enderror
                </div>
                <button class="w-full rounded-full bg-[#1d4d3a] px-5 py-3.5 text-sm font-bold text-white transition hover:-translate-y-0.5 hover:bg-[#123628]" type="submit">Kirim OTP</button>
            @endif
        </form>
        @if(session('seller_otp.challenge_id'))
            <form method="POST" action="{{ route('seller.logout') }}" class="mt-4 text-center">
                @csrf
                <button class="text-sm font-semibold text-[#68776d] underline underline-offset-4" type="submit">Gunakan nomor lain</button>
            </form>
        @endif
    </div>
@endsection
