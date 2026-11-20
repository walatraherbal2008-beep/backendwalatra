<x-filament-widgets::widget>
    <x-filament::section>
        @php
            $statusClass = match ($run?->status) {
                'running' => 'warehouse-stock-sync-status--running',
                'completed' => 'warehouse-stock-sync-status--completed',
                'failed' => 'warehouse-stock-sync-status--failed',
                default => 'warehouse-stock-sync-status--idle',
            };
        @endphp

        <style>
            .warehouse-stock-sync-layout { display: flex; align-items: flex-start; gap: .75rem; }
            .warehouse-stock-sync-spinner { width: 1.25rem; height: 1.25rem; flex: none; margin-top: .125rem; color: var(--primary-600); }
            .warehouse-stock-sync-content { min-width: 0; flex: 1; }
            .warehouse-stock-sync-header { display: flex; flex-wrap: wrap; align-items: baseline; justify-content: space-between; gap: .25rem 1rem; }
            .warehouse-stock-sync-title { color: var(--gray-950); font-size: .875rem; font-weight: 500; }
            .warehouse-stock-sync-status { font-size: 1.125rem; font-weight: 600; }
            .warehouse-stock-sync-copy { margin-top: .25rem; color: var(--gray-500); font-size: .875rem; }
            .warehouse-stock-sync-track { height: .5rem; margin-top: .75rem; overflow: hidden; border-radius: 9999px; background-color: var(--gray-200); }
            .warehouse-stock-sync-progress-fill { height: 100%; border-radius: 9999px; background-color: var(--primary-600); }
            .warehouse-stock-sync-percent { margin-top: .25rem; color: var(--gray-500); font-size: .75rem; font-variant-numeric: tabular-nums; }
            .warehouse-stock-sync-error { margin-top: .5rem; color: var(--danger-600); font-size: .875rem; }
            .warehouse-stock-sync-status--idle { color: var(--gray-500); }
            .warehouse-stock-sync-status--running { color: var(--primary-600); }
            .warehouse-stock-sync-status--completed { color: var(--success-600); }
            .warehouse-stock-sync-status--failed { color: var(--danger-600); }
            .dark .warehouse-stock-sync-title { color: var(--gray-50); }
            .dark .warehouse-stock-sync-copy, .dark .warehouse-stock-sync-percent { color: var(--gray-400); }
            .dark .warehouse-stock-sync-track { background-color: var(--gray-700); }
            .dark .warehouse-stock-sync-progress-fill { background-color: var(--primary-400); }
            .dark .warehouse-stock-sync-error { color: var(--danger-400); }
            .dark .warehouse-stock-sync-status--idle { color: var(--gray-400); }
            .dark .warehouse-stock-sync-status--running { color: var(--primary-400); }
            .dark .warehouse-stock-sync-status--completed { color: var(--success-400); }
            .dark .warehouse-stock-sync-status--failed { color: var(--danger-400); }
            .dark .warehouse-stock-sync-spinner { color: var(--primary-400); }
            @media (prefers-reduced-motion: no-preference) {
                .warehouse-stock-sync-spinner { animation: var(--animate-spin); }
                .warehouse-stock-sync-progress-fill { transition: width 300ms ease-out; }
            }
        </style>

        <div class="warehouse-stock-sync-layout" aria-busy="{{ $isActive ? 'true' : 'false' }}">
            @if ($isActive)
                <svg class="warehouse-stock-sync-spinner mt-0.5 size-5 shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" aria-hidden="true">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z" />
                </svg>
            @endif

            <div class="warehouse-stock-sync-content">
                <div class="warehouse-stock-sync-header">
                    <h2 class="warehouse-stock-sync-title">Sinkronisasi stok</h2>
                    <span class="warehouse-stock-sync-status {{ $statusClass }}" role="status" aria-live="polite" aria-atomic="true">{{ $status }}</span>
                </div>

                @if ($run)
                    <p class="warehouse-stock-sync-copy">
                        {{ number_format($run->checked, 0, ',', '.') }} dari {{ number_format($run->total, 0, ',', '.') }} diperiksa
                        <span aria-hidden="true">·</span>
                        {{ number_format($run->succeeded, 0, ',', '.') }} berhasil
                        <span aria-hidden="true">·</span>
                        {{ number_format($run->failed, 0, ',', '.') }} gagal
                        @if ($timestamp)
                            <span aria-hidden="true">·</span> {{ $timestamp }}
                        @endif
                    </p>
                @else
                    <p class="warehouse-stock-sync-copy">Belum ada sinkronisasi stok.</p>
                @endif

                @if ($isActive || ($run && $run->total > 0))
                    <div role="progressbar" aria-label="Progres sinkronisasi stok" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $percentage }}">
                        <div class="warehouse-stock-sync-track">
                            <div class="warehouse-stock-sync-progress-fill" style="width: {{ $percentage }}%"></div>
                        </div>
                    </div>
                    <p class="warehouse-stock-sync-percent">{{ $percentage }}%</p>
                @endif

                @if ($run?->error)
                    <p class="warehouse-stock-sync-error">{{ $run->error }}</p>
                @endif
            </div>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
