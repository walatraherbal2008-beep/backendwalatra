@props(['whatsappUrl' => null])

<section id="atas" class="relative isolate overflow-hidden bg-gradient-to-br from-[#f8f5ec] via-[#f1f6ec] to-[#e5f0e4]" aria-labelledby="hero-title">
    <div class="pointer-events-none absolute -bottom-48 -right-40 -z-10 size-[28rem] rounded-full border border-[#1d4d3a]/10 shadow-[0_0_0_42px_rgba(29,77,58,.035),0_0_0_84px_rgba(29,77,58,.025)]" aria-hidden="true"></div>

    <div class="mx-auto grid w-[min(100%-2rem,1120px)] grid-cols-1 items-center gap-10 py-16 sm:w-[min(100%-3rem,1120px)] md:gap-12 md:py-24 lg:grid-cols-[1.08fr_.92fr] lg:gap-16 lg:py-28">
        <div>
            <p class="mb-5 inline-flex items-center gap-2 text-xs font-extrabold uppercase tracking-[.13em] text-[#c59445]">
                <span class="h-0.5 w-7 bg-[#c59445]" aria-hidden="true"></span>
                Pilihan herbal keluarga Indonesia
            </p>

            <h1 id="hero-title" class="max-w-3xl font-serif text-[clamp(2.75rem,6vw,4.75rem)] font-medium leading-[1.02] tracking-[-.045em] text-[#123628]">
                Rawat sehatmu dengan kebaikan <em class="text-[#1d4d3a]">alam.</em>
            </h1>

            <p class="mt-6 max-w-xl text-base leading-8 text-[#68776d] sm:text-lg">
                Walatra Herbal menghadirkan pilihan produk herbal yang dirancang untuk menemani keluarga menjalani hidup sehat, seimbang, dan penuh makna.
            </p>

            <div class="mt-8 flex flex-wrap items-center gap-3">
                @if(filled($whatsappUrl))
                    <a class="inline-flex min-h-12 cursor-pointer items-center justify-center rounded-full bg-[#1d4d3a] px-6 py-3 text-sm font-extrabold text-[#fffdf8] shadow-sm transition duration-200 ease-out hover:-translate-y-0.5 hover:bg-[#123628] hover:shadow-[0_10px_22px_rgba(18,54,40,.14)] active:scale-[.98] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-[#c59445] motion-reduce:transform-none motion-reduce:transition-none" href="{{ $whatsappUrl }}" target="_blank" rel="noopener noreferrer">
                        Hubungi Kami
                    </a>
                @endif
                <a class="inline-flex min-h-12 cursor-pointer items-center justify-center rounded-full border border-[#1d4d3a]/20 bg-[#fffdf8]/60 px-6 py-3 text-sm font-extrabold text-[#1d4d3a] transition duration-200 ease-out hover:-translate-y-0.5 hover:border-[#1d4d3a]/40 hover:bg-[#fffdf8] hover:shadow-[0_10px_22px_rgba(18,54,40,.08)] active:scale-[.98] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-[#c59445] motion-reduce:transform-none motion-reduce:transition-none" href="#produk">
                    Jelajahi Produk
                </a>
            </div>
        </div>

        <div class="relative z-0 grid min-h-[21rem] place-items-center md:min-h-[25rem]" aria-hidden="true">
            <div class="relative aspect-[4/5] w-[min(100%,370px)] overflow-hidden rounded-[190px_190px_28px_28px] bg-[#1d4d3a] shadow-[24px_30px_0_rgba(197,148,69,.18)]">
                <span class="absolute left-[20%] top-1/4 h-[250px] w-[150px] -rotate-[36deg] rounded-[50%_0_50%_50%] border border-[#fffdf8]/25"></span>
                <span class="absolute bottom-[12%] right-[12%] h-[210px] w-[130px] rotate-[35deg] rounded-[50%_0_50%_50%] border border-[#fffdf8]/25"></span>
                <div class="absolute bottom-8 left-8 right-8 text-[#fffdf8] sm:bottom-9 sm:left-9 sm:right-9">
                    <strong class="block font-serif text-4xl font-medium leading-none tracking-[-.04em]">Hidup lebih alami.</strong>
                    <span class="mt-2 block text-[13px] text-[#c9dbc5]">Teman sehat untuk setiap langkah.</span>
                </div>
            </div>
        </div>
    </div>
</section>
