@extends('seller.layout', ['title' => 'Daftar Seller dan Toko | Walatra Herbal'])

@section('content')
    <div class="mx-auto max-w-3xl">
        <div class="mb-8">
            <p class="text-sm font-bold uppercase tracking-[.14em] text-[#c59445]">Pendaftaran seller</p>
            <h1 class="mt-3 font-serif text-4xl leading-tight tracking-tight text-[#123628] sm:text-5xl">Bangun toko herbalmu bersama Walatra.</h1>
            <p class="mt-4 max-w-2xl text-sm leading-7 text-[#68776d]">Lengkapi data seller dan toko. Pengajuan akan diperiksa oleh tim Walatra sebelum toko dapat beroperasi.</p>
        </div>

        <div class="mb-6 grid grid-cols-3 gap-2" aria-label="Tahap pendaftaran">
            @foreach(['Akun seller', 'Identitas toko', 'Alamat toko'] as $index => $label)
                <div class="step-indicator rounded-2xl bg-[#e5f0e4] px-3 py-3 text-center text-xs font-bold text-[#1d4d3a]" data-step-indicator="{{ $index + 1 }}"><span class="block text-[#c59445]">0{{ $index + 1 }}</span>{{ $label }}</div>
            @endforeach
        </div>

        <form method="POST" action="{{ route('seller.register.store') }}" class="rounded-3xl bg-[#fffdf8] p-6 shadow-xl shadow-[#1d4d3a]/5 sm:p-10" id="seller-registration-form">
            @csrf
            @if($errors->any())
                <div class="mb-6 rounded-2xl border border-red-200 bg-red-50 p-4 text-sm text-red-800" role="alert">Periksa kembali data yang ditandai.</div>
            @endif
            <section class="registration-step space-y-5" data-step="1">
                <h2 class="font-serif text-2xl text-[#123628]">Data akun seller</h2>
                <div><label for="name" class="block text-sm font-semibold">Nama lengkap</label><input id="name" name="name" required value="{{ old('name', $user->name) }}" class="mt-2 w-full rounded-2xl border border-[#1d4d3a]/15 bg-white px-4 py-3 outline-none focus:border-[#1d4d3a] focus:ring-4 focus:ring-[#1d4d3a]/10">@error('name')<p class="mt-2 text-sm text-red-700">{{ $message }}</p>@enderror</div>
                <div><label for="email" class="block text-sm font-semibold">Email</label><input id="email" name="email" type="email" required value="{{ old('email', $user->email) }}" class="mt-2 w-full rounded-2xl border border-[#1d4d3a]/15 bg-white px-4 py-3 outline-none focus:border-[#1d4d3a] focus:ring-4 focus:ring-[#1d4d3a]/10">@error('email')<p class="mt-2 text-sm text-red-700">{{ $message }}</p>@enderror</div>
                <div><label for="password" class="block text-sm font-semibold">Password <span class="font-normal text-[#68776d]">(minimal 6 karakter)</span></label><input id="password" name="password" type="password" minlength="6" required class="mt-2 w-full rounded-2xl border border-[#1d4d3a]/15 bg-white px-4 py-3 outline-none focus:border-[#1d4d3a] focus:ring-4 focus:ring-[#1d4d3a]/10">@error('password')<p class="mt-2 text-sm text-red-700">{{ $message }}</p>@enderror</div>
            </section>
            <section class="registration-step hidden space-y-5" data-step="2">
                <h2 class="font-serif text-2xl text-[#123628]">Identitas toko</h2>
                <div><label for="store_name" class="block text-sm font-semibold">Nama toko</label><input id="store_name" name="store_name" required value="{{ old('store_name') }}" class="mt-2 w-full rounded-2xl border border-[#1d4d3a]/15 bg-white px-4 py-3 outline-none focus:border-[#1d4d3a] focus:ring-4 focus:ring-[#1d4d3a]/10">@error('store_name')<p class="mt-2 text-sm text-red-700">{{ $message }}</p>@enderror</div>
                <div><label for="description" class="block text-sm font-semibold">Deskripsi toko</label><textarea id="description" name="description" rows="4" class="mt-2 w-full rounded-2xl border border-[#1d4d3a]/15 bg-white px-4 py-3 outline-none focus:border-[#1d4d3a] focus:ring-4 focus:ring-[#1d4d3a]/10">{{ old('description') }}</textarea>@error('description')<p class="mt-2 text-sm text-red-700">{{ $message }}</p>@enderror</div>
                <div><label for="contact_phone" class="block text-sm font-semibold">Nomor kontak toko <span class="font-normal text-[#68776d]">(opsional)</span></label><input id="contact_phone" name="contact_phone" type="tel" value="{{ old('contact_phone', $user->phone) }}" class="mt-2 w-full rounded-2xl border border-[#1d4d3a]/15 bg-white px-4 py-3 outline-none focus:border-[#1d4d3a] focus:ring-4 focus:ring-[#1d4d3a]/10">@error('contact_phone')<p class="mt-2 text-sm text-red-700">{{ $message }}</p>@enderror</div>
            </section>
            <section class="registration-step hidden space-y-5" data-step="3">
                <h2 class="font-serif text-2xl text-[#123628]">Alamat toko</h2>
                <div class="grid gap-5 sm:grid-cols-2"><div><label for="province" class="block text-sm font-semibold">Provinsi</label><input id="province" name="province" value="{{ old('province') }}" class="mt-2 w-full rounded-2xl border border-[#1d4d3a]/15 bg-white px-4 py-3 outline-none focus:border-[#1d4d3a] focus:ring-4 focus:ring-[#1d4d3a]/10">@error('province')<p class="mt-2 text-sm text-red-700">{{ $message }}</p>@enderror</div><div><label for="city" class="block text-sm font-semibold">Kota/Kabupaten</label><input id="city" name="city" value="{{ old('city') }}" class="mt-2 w-full rounded-2xl border border-[#1d4d3a]/15 bg-white px-4 py-3 outline-none focus:border-[#1d4d3a] focus:ring-4 focus:ring-[#1d4d3a]/10">@error('city')<p class="mt-2 text-sm text-red-700">{{ $message }}</p>@enderror</div><div><label for="district" class="block text-sm font-semibold">Kecamatan</label><input id="district" name="district" value="{{ old('district') }}" class="mt-2 w-full rounded-2xl border border-[#1d4d3a]/15 bg-white px-4 py-3 outline-none focus:border-[#1d4d3a] focus:ring-4 focus:ring-[#1d4d3a]/10">@error('district')<p class="mt-2 text-sm text-red-700">{{ $message }}</p>@enderror</div><div><label for="postal_code" class="block text-sm font-semibold">Kode pos</label><input id="postal_code" name="postal_code" value="{{ old('postal_code') }}" class="mt-2 w-full rounded-2xl border border-[#1d4d3a]/15 bg-white px-4 py-3 outline-none focus:border-[#1d4d3a] focus:ring-4 focus:ring-[#1d4d3a]/10">@error('postal_code')<p class="mt-2 text-sm text-red-700">{{ $message }}</p>@enderror</div></div>
                <div><label for="address" class="block text-sm font-semibold">Alamat lengkap</label><textarea id="address" name="address" rows="4" class="mt-2 w-full rounded-2xl border border-[#1d4d3a]/15 bg-white px-4 py-3 outline-none focus:border-[#1d4d3a] focus:ring-4 focus:ring-[#1d4d3a]/10">{{ old('address') }}</textarea>@error('address')<p class="mt-2 text-sm text-red-700">{{ $message }}</p>@enderror</div>
            </section>
            <div class="mt-8 flex flex-wrap justify-between gap-3 border-t border-[#1d4d3a]/10 pt-6"><button type="button" id="previous-step" class="hidden rounded-full border border-[#1d4d3a]/20 px-5 py-3 text-sm font-bold text-[#1d4d3a]">Kembali</button><span></span><button type="button" id="next-step" class="rounded-full bg-[#1d4d3a] px-6 py-3 text-sm font-bold text-white transition hover:-translate-y-0.5 hover:bg-[#123628]">Lanjut</button><button type="submit" id="submit-registration" class="hidden rounded-full bg-[#1d4d3a] px-6 py-3 text-sm font-bold text-white transition hover:-translate-y-0.5 hover:bg-[#123628]">Kirim pengajuan toko</button></div>
        </form>
    </div>
    <script>
        (() => { const steps = [...document.querySelectorAll('.registration-step')], indicators = [...document.querySelectorAll('[data-step-indicator]')], next = document.querySelector('#next-step'), previous = document.querySelector('#previous-step'), submit = document.querySelector('#submit-registration'); let current = 1; const render = () => { steps.forEach((step) => step.classList.toggle('hidden', Number(step.dataset.step) !== current)); indicators.forEach((item) => item.classList.toggle('bg-[#1d4d3a]', Number(item.dataset.stepIndicator) === current)); indicators.forEach((item) => item.classList.toggle('text-white', Number(item.dataset.stepIndicator) === current)); previous.classList.toggle('hidden', current === 1); next.classList.toggle('hidden', current === steps.length); submit.classList.toggle('hidden', current !== steps.length); }; next.addEventListener('click', () => { const fields = steps[current - 1].querySelectorAll('input, textarea'); for (const field of fields) { if (!field.reportValidity()) return; } current++; render(); }); previous.addEventListener('click', () => { current--; render(); }); render(); })();
    </script>
@endsection
