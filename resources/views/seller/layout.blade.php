<!DOCTYPE html>
<html lang="id">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="description" content="Pendaftaran seller dan toko Walatra Herbal.">
        <meta name="theme-color" content="#1d4d3a">
        <title>{{ $title ?? 'Pendaftaran Seller | Walatra Herbal' }}</title>
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="min-h-screen bg-[#f8f5ec] text-[#173127] antialiased">
        <header class="border-b border-[#1d4d3a]/10 bg-[#fffdf8]/90">
            <div class="mx-auto flex w-[min(100%-2rem,960px)] items-center justify-between py-5 sm:w-[min(100%-3rem,960px)]">
                <a href="{{ url('/') }}" class="font-serif text-xl font-semibold tracking-tight text-[#1d4d3a]">Walatra Herbal</a>
                @auth
                    <form method="POST" action="{{ route('seller.logout') }}">
                        @csrf
                        <button class="rounded-full border border-[#1d4d3a]/20 px-4 py-2 text-sm font-semibold text-[#1d4d3a] transition hover:bg-[#e5f0e4]">Keluar</button>
                    </form>
                @endauth
            </div>
        </header>
        <main class="mx-auto w-[min(100%-2rem,960px)] py-10 sm:w-[min(100%-3rem,960px)] sm:py-16">
            @if(session('success'))
                <div class="mb-6 rounded-2xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-800" role="status">{{ session('success') }}</div>
            @endif
            @if($errors->has('registration'))
                <div class="mb-6 rounded-2xl border border-red-200 bg-red-50 p-4 text-sm text-red-800" role="alert">{{ $errors->first('registration') }}</div>
            @endif
            @yield('content')
        </main>
    </body>
</html>
