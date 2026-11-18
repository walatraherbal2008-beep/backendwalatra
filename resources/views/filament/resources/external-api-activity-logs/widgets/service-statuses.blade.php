<x-filament-widgets::widget>
    <x-filament::section
        heading="Status layanan"
        description="Status berdasarkan konfigurasi dan aktivitas request terakhir. Data diperbarui setiap 5 detik."
    >
        <div
            wire:poll.5s
            style="display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 15rem), 1fr)); gap: 0.75rem;"
        >
            @foreach ($services as $service)
                <x-filament::section
                    compact
                    secondary
                    style="min-width: 0;"
                >
                    <div style="display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 0.5rem;">
                        <strong class="fi-section-header-heading" style="font-size: 0.875rem;">{{ $service['label'] }}</strong>
                        <x-filament::badge :color="$service['color']">{{ $service['status'] }}</x-filament::badge>
                    </div>
                    <p class="fi-section-header-description" style="margin: 0.75rem 0 0; font-size: 0.75rem; line-height: 1.25rem;">
                        Aktivitas terakhir<br>
                        <span class="fi-section-header-heading" style="font-size: inherit; line-height: inherit; font-weight: 500;">{{ $service['last_activity'] ?? 'Belum tercatat' }}</span>
                    </p>
                </x-filament::section>
            @endforeach
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
