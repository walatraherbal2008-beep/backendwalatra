<?php

namespace App\Filament\Resources\ExternalApiActivityLogs\Pages;

use App\Filament\Resources\ExternalApiActivityLogs\ExternalApiActivityLogResource;
use App\Filament\Resources\ExternalApiActivityLogs\Widgets\ExternalApiActivityStats;
use App\Filament\Resources\ExternalApiActivityLogs\Widgets\ExternalApiServiceStatuses;
use App\Services\ExternalApiHealthChecker;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListExternalApiActivityLogs extends ListRecords
{
    protected static string $resource = ExternalApiActivityLogResource::class;

    protected function authorizeAccess(): void
    {
        abort_unless(ExternalApiActivityLogResource::canAccess(), 403);
    }

    protected function getHeaderWidgets(): array
    {
        return [ExternalApiActivityStats::class, ExternalApiServiceStatuses::class];
    }

    public function runConnectionCheck(): void
    {
        abort_unless(ExternalApiActivityLogResource::canAccess(), 403);

        $selectedService = blank($this->activeTab) || $this->activeTab === 'all'
            ? null
            : $this->activeTab;
        $results = app(ExternalApiHealthChecker::class)->check($selectedService);
        $failed = collect($results)->whereIn('status', ['failed', 'timeout'])->count();

        $notification = Notification::make()
            ->title($failed > 0 ? 'Pemeriksaan selesai dengan kendala' : 'Pemeriksaan koneksi selesai')
            ->body(collect($results)
                ->map(fn (array $result): string => $result['label'].': '.$result['detail'])
                ->implode("\n"));

        ($failed > 0 ? $notification->warning() : $notification->success())->send();
    }

    public function connectionCheckTitle(): string
    {
        if (blank($this->activeTab) || $this->activeTab === 'all') {
            return 'Uji semua endpoint API aktif';
        }

        return 'Uji koneksi '.(ExternalApiActivityLogResource::serviceLabels()[$this->activeTab] ?? 'API');
    }

    public function getHeaderWidgetsColumns(): int|array
    {
        return 1;
    }

    public function getTabs(): array
    {
        abort_unless(ExternalApiActivityLogResource::canAccess(), 403);

        $tabs = ['all' => Tab::make('Semua Aktivitas')];

        foreach (ExternalApiActivityLogResource::serviceLabels() as $service => $label) {
            $tabs[$service] = Tab::make($label)
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('service', $service));
        }

        return $tabs;
    }
}
