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
                <article
                    style="min-width: 0; border: 1px solid var(--gray-200, #e5e7eb); border-radius: 0.75rem; background: var(--gray-50, #f9fafb); padding: 1rem;"
                >
                    <div style="display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 0.5rem;">
                        <strong style="font-size: 0.875rem; font-weight: 600;">{{ $service['label'] }}</strong>
                        <x-filament::badge :color="$service['color']">{{ $service['status'] }}</x-filament::badge>
                    </div>
                    <p style="margin: 0.75rem 0 0; color: var(--gray-500, #6b7280); font-size: 0.75rem; line-height: 1.25rem;">
                        Aktivitas terakhir<br>
                        <span style="color: var(--gray-700, #374151); font-weight: 500;">{{ $service['last_activity'] ?? 'Belum tercatat' }}</span>
                    </p>
                </article>
            @endforeach
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
