<!DOCTYPE html>
<html lang="id">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="description" content="Walatra Herbal menghadirkan produk herbal pilihan untuk mendukung keluarga Indonesia hidup lebih sehat.">
        <meta name="theme-color" content="#1d4d3a">
        <meta property="og:title" content="Walatra Herbal | Pilihan Herbal Keluarga Indonesia">
        <meta property="og:description" content="Temukan pilihan produk herbal Walatra untuk menemani hidup sehat setiap hari.">
        <meta property="og:type" content="website">
        <title>Walatra Herbal | Pilihan Herbal Keluarga Indonesia</title>
        @vite(['resources/css/app.css', 'resources/js/app.js'])
        <style>
            :root { --forest:#1d4d3a; --deep-forest:#123628; --sage:#dfead8; --cream:#f8f5ec; --gold:#c59445; --ink:#173127; --muted:#68776d; --white:#fffdf8; }
            * { box-sizing: border-box; }
            html { scroll-behavior: smooth; }
            body { margin: 0; color: var(--ink); background: var(--white); font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; line-height: 1.6; }
            a { color: inherit; text-decoration: none; }
            .shell { width: min(1120px, calc(100% - 40px)); margin: 0 auto; }
            .topbar { position: relative; z-index: 2; display: flex; align-items: center; justify-content: space-between; padding: 24px 0; }
            .brand { display: inline-flex; align-items: center; gap: 12px; color: var(--forest); font-weight: 800; letter-spacing: -.03em; }
            .brand-mark { display: grid; width: 42px; height: 42px; place-items: center; color: var(--white); background: var(--forest); border-radius: 14px 14px 14px 4px; font-family: Georgia, serif; font-size: 22px; font-style: italic; }
            .brand-name { font-size: 19px; }
            .nav { display: flex; align-items: center; gap: 28px; color: var(--muted); font-size: 14px; font-weight: 600; }
            .nav a:hover { color: var(--forest); }
            .nav-cta { padding: 10px 16px; color: var(--white) !important; background: var(--forest); border-radius: 999px; }
            .hero-wrap { overflow: hidden; background: linear-gradient(135deg, var(--cream) 0%, #edf4e8 100%); }
            h1, h2 { margin: 0; font-family: Georgia, "Times New Roman", serif; font-weight: 500; letter-spacing: -.045em; }
            .button { display: inline-flex; align-items: center; justify-content: center; min-height: 48px; padding: 0 22px; border: 1px solid transparent; border-radius: 999px; font-size: 14px; font-weight: 800; transition: transform .2s ease, box-shadow .2s ease, background .2s ease; }
            .button:hover { transform: translateY(-2px); box-shadow: 0 10px 22px rgba(18, 54, 40, .14); }
            .button-primary { color: var(--white); background: var(--forest); }
            .button-secondary { color: var(--forest); border-color: rgba(29, 77, 58, .22); background: rgba(255, 253, 248, .55); }
            .section { padding: 92px 0; }
            .section-soft { background: var(--cream); }
            .section-heading { display: flex; align-items: end; justify-content: space-between; gap: 24px; margin-bottom: 34px; }
            h2 { color: var(--deep-forest); font-size: clamp(34px, 4vw, 52px); line-height: 1.05; }
            .section-intro { max-width: 430px; margin: 0; color: var(--muted); }
            .category-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 14px; }
            .category { min-height: 150px; padding: 24px; border: 1px solid rgba(29, 77, 58, .1); border-radius: 20px; background: var(--white); transition: transform .2s ease, box-shadow .2s ease; }
            .category:hover { transform: translateY(-4px); box-shadow: 0 14px 30px rgba(18, 54, 40, .08); }
            .category-number { color: var(--gold); font-size: 12px; font-weight: 800; letter-spacing: .08em; }
            .category h3 { margin: 24px 0 0; color: var(--forest); font-family: Georgia, serif; font-size: 23px; font-weight: 500; }
            .benefit-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 34px; }
            .benefit-icon { display: grid; width: 42px; height: 42px; margin-bottom: 20px; place-items: center; color: var(--forest); background: var(--sage); border-radius: 14px; font-family: Georgia, serif; font-size: 21px; }
            .benefit h3 { margin: 0 0 8px; color: var(--deep-forest); font-size: 18px; }
            .benefit p { margin: 0; color: var(--muted); font-size: 14px; }
            .closing { padding: 86px 0; color: var(--white); background: var(--deep-forest); }
            .closing-inner { display: flex; align-items: center; justify-content: space-between; gap: 32px; }
            .closing h2 { max-width: 620px; color: var(--white); }
            .closing p { max-width: 430px; margin: 16px 0 0; color: #b9cbbd; }
            .button-light { color: var(--deep-forest); background: var(--white); white-space: nowrap; }
            footer { padding: 28px 0; color: #819188; background: #0e2b20; font-size: 13px; }
            .footer-inner { display: flex; align-items: center; justify-content: space-between; gap: 20px; }
            .footer-brand { color: var(--white); font-weight: 800; }
            :focus-visible { outline: 3px solid var(--gold); outline-offset: 3px; }
            @media (max-width: 800px) { .nav a:not(.nav-cta) { display: none; } .section-heading, .closing-inner { display: block; } .section-intro { margin-top: 18px; } .closing .button { margin-top: 28px; } }
            @media (max-width: 560px) { .shell { width: min(100% - 28px, 1120px); } .topbar { padding: 18px 0; } .brand-name { font-size: 17px; } .category-grid, .benefit-grid { grid-template-columns: 1fr; } .section { padding: 68px 0; } .footer-inner { display: block; } .footer-inner span { display: block; margin-top: 8px; } }
            @media (prefers-reduced-motion: reduce) { html { scroll-behavior: auto; } .button, .category { transition: none; } .button:hover, .category:hover { transform: none; } }
        </style>
    </head>
    <body>
        @php($whatsappUrl = config('services.company.whatsapp_url'))
        <div class="hero-wrap">
            <header class="shell topbar">
                <a class="brand" href="#atas" aria-label="Walatra Herbal"><span class="brand-mark" aria-hidden="true">W</span><span class="brand-name">Walatra Herbal</span></a>
                <nav class="nav" aria-label="Navigasi utama">
                    <a href="#produk">Produk</a>
                    <a href="#keunggulan">Keunggulan</a>
                    @if(filled($whatsappUrl))<a class="nav-cta" href="{{ $whatsappUrl }}" target="_blank" rel="noopener noreferrer">Hubungi Kami</a>@endif
                </nav>
            </header>
            <main>
                <x-landing.hero :whatsapp-url="$whatsappUrl" />
            </main>
        </div>
        <section class="section" id="produk">
            <div class="shell">
                <div class="section-heading"><h2>Pilihan untuk setiap kebutuhan.</h2><p class="section-intro">Temukan rangkaian produk herbal pilihan untuk mendukung keseharian Anda dan keluarga.</p></div>
                <div class="category-grid">
                    @foreach(['Pencernaan', 'Daya Tahan Tubuh', 'Sendi dan Otot', 'Kesehatan Wanita', 'Nutrisi dan Kebugaran', 'Kesehatan Kulit'] as $index => $category)
                        <article class="category"><div class="category-number">0{{ $index + 1 }}</div><h3>{{ $category }}</h3></article>
                    @endforeach
                </div>
            </div>
        </section>
        <section class="section section-soft" id="keunggulan">
            <div class="shell">
                <div class="section-heading"><h2>Dipilih dengan kepedulian.</h2><p class="section-intro">Kami percaya kesehatan yang baik dimulai dari pilihan yang baik.</p></div>
                <div class="benefit-grid">
                    <article class="benefit"><div class="benefit-icon" aria-hidden="true"><svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 3 14.2 9.8 21 12l-6.8 2.2L12 21l-2.2-6.8L3 12l6.8-2.2L12 3Z"/></svg></div><h3>Produk herbal resmi</h3><p>Rangkaian produk Walatra dikembangkan untuk menjadi pilihan herbal yang dapat diandalkan.</p></article>
                    <article class="benefit"><div class="benefit-icon" aria-hidden="true"><svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8"><path d="m5 12 4.2 4.2L19 6.5"/><path d="M12 3.5a8.5 8.5 0 1 0 8.5 8.5"/></svg></div><h3>Kualitas dan keamanan</h3><p>Kami menjaga kualitas produk agar setiap pilihan dapat menemani rutinitas sehat Anda.</p></article>
                    <article class="benefit"><div class="benefit-icon" aria-hidden="true"><svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M16 20v-1.5a4 4 0 0 0-4-4H7a4 4 0 0 0-4 4V20"/><circle cx="9.5" cy="7" r="3.5"/><path d="M17 11a3 3 0 1 0 0-6M21 20v-1.5a4 4 0 0 0-3-3.87"/></svg></div><h3>Jaringan member</h3><p>Didukung jaringan member yang tumbuh bersama untuk menjangkau lebih banyak keluarga.</p></article>
                </div>
            </div>
        </section>
        <section class="closing"><div class="shell closing-inner"><div><h2>Mulai langkah sehatmu hari ini.</h2><p>Hubungi kami untuk mendapatkan informasi produk dan layanan Walatra Herbal.</p></div>@if(filled($whatsappUrl))<a class="button button-light" href="{{ $whatsappUrl }}" target="_blank" rel="noopener noreferrer">Hubungi Kami</a>@endif</div></section>
        <footer><div class="shell footer-inner"><span class="footer-brand">Walatra Herbal</span><span>Menemani keluarga Indonesia hidup lebih sehat.</span></div></footer>
    </body>
</html>
