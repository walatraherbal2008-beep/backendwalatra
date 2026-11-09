<?php

namespace App\Filament\Pages;

use App\Contracts\WarehouseDirectoryProvider;
use App\Exceptions\WarehouseDirectoryUnavailableException;
use App\Services\Warehouse\ExternalWarehouseDirectoryClient;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Exceptions\Halt;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Collection;
use UnitEnum;

class ShippingPoints extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $title = 'Titik Pengiriman';

    protected static ?string $navigationLabel = 'Titik Pengiriman';

    protected static string|UnitEnum|null $navigationGroup = 'Manajemen Marketplace';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMapPin;

    protected static ?int $navigationSort = 6;

    protected static ?string $slug = 'titik-pengiriman';

    protected string $view = 'filament.pages.shipping-points';

    private ?array $points = null;

    private ?string $directoryError = null;

    public static function canAccess(): bool
    {
        return auth()->user()?->status === 'active' && auth()->user()->hasRole('super_admin');
    }

    private function directory(): array
    {
        if ($this->points !== null) {
            return $this->points;
        }

        try {
            return $this->points = app(WarehouseDirectoryProvider::class)->list();
        } catch (WarehouseDirectoryUnavailableException) {
            $this->directoryError = 'Periksa koneksi layanan, lalu coba muat ulang.';
            Notification::make()->danger()->title('Titik pengiriman belum dapat dimuat')
                ->body($this->directoryError)->send();

            return $this->points = [];
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('reload')->label('Muat ulang')->icon(Heroicon::OutlinedArrowPath)
                ->action(function (): void {
                    $this->points = null;
                    $this->directoryError = null;
                    $this->resetTable();
                }),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(function (?string $search, array $filters): Collection {
                $area = $filters['area_group']['value'] ?? null;
                $keyword = mb_strtolower(trim((string) $search));

                return collect($this->directory())
                    ->when(filled($area), fn (Collection $rows): Collection => $rows->where('area_group', $area))
                    ->when($keyword !== '', fn (Collection $rows): Collection => $rows->filter(
                        fn (array $row): bool => str_contains(mb_strtolower($row['realname'].' '.$row['codename']), $keyword),
                    ))
                    ->sortBy(fn (array $row): string => mb_strtolower($row['realname']))
                    ->keyBy('code');
            })
            ->searchable()
            ->paginated(false)
            ->recordTitleAttribute('realname')
            ->columns([
                TextColumn::make('realname')->label('Nama titik pengiriman')->wrap(),
                TextColumn::make('codename')->label('Wilayah')->wrap(),
                TextColumn::make('area_group')->label('Grup')->badge()
                    ->formatStateUsing(fn (string $state): string => self::areaLabel($state)),
            ])
            ->filters([
                SelectFilter::make('area_group')->label('Grup wilayah')->options([
                    'pulaujawa' => 'Pulau Jawa',
                    'luarjawa' => 'Luar Jawa',
                ]),
            ])
            ->emptyStateHeading(fn (): string => $this->directoryError !== null
                ? 'Titik pengiriman belum dapat dimuat' : 'Tidak ada titik pengiriman')
            ->emptyStateDescription(fn (): string => $this->directoryError
                ?? 'Belum ada data yang sesuai dengan pencarian atau filter.')
            ->recordActions([
                Action::make('viewDetails')->label('Lihat detail')->icon(Heroicon::OutlinedEye)
                    ->authorize(fn (): bool => static::canAccess())
                    ->slideOver()
                    ->modalHeading('Detail Titik Pengiriman')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Tutup')
                    ->fillForm(function (array $record): array {
                        try {
                            $detail = app(ExternalWarehouseDirectoryClient::class)->detail($record['code']);
                        } catch (WarehouseDirectoryUnavailableException) {
                            Notification::make()->danger()->title('Detail titik pengiriman belum dapat dimuat')
                                ->body('Silakan tutup dan coba lihat detail kembali.')->send();

                            throw new Halt;
                        }

                        return [...$detail, 'code' => $record['code'], 'map_url' => self::mapUrl($detail['coordinates'])];
                    })
                    ->schema([
                        TextEntry::make('realname')->label('Nama titik pengiriman')->state(fn () => $this->detailValue('realname')),
                        TextEntry::make('code')->label('Kode')->state(fn () => $this->detailValue('code')),
                        TextEntry::make('codename')->label('Wilayah')->state(fn () => $this->detailValue('codename')),
                        TextEntry::make('area_group')->label('Grup')
                            ->state(fn () => $this->detailValue('area_group'))
                            ->formatStateUsing(fn (string $state): string => self::areaLabel($state)),
                        TextEntry::make('fulladdr')->label('Alamat lengkap')->placeholder('Belum tersedia')->state(fn () => $this->detailValue('fulladdr')),
                        TextEntry::make('postal_code')->label('Kode pos')->placeholder('Belum tersedia')->state(fn () => $this->detailValue('postal_code')),
                        TextEntry::make('coordinates')->label('Koordinat')->placeholder('Belum tersedia')->state(fn () => $this->detailValue('coordinates')),
                        TextEntry::make('map_url')->label('Peta')->placeholder('Belum tersedia')
                            ->state(fn () => $this->detailValue('map_url'))
                            ->formatStateUsing(fn (): string => 'Buka di Google Maps')
                            ->url(fn (?string $state): ?string => $state)->openUrlInNewTab(),
                    ]),
            ]);
    }

    private function detailValue(string $field): mixed
    {
        return $this->getMountedAction()?->getRawData()[$field] ?? null;
    }

    private static function areaLabel(string $area): string
    {
        return match ($area) {
            'pulaujawa' => 'Pulau Jawa',
            'luarjawa' => 'Luar Jawa',
            default => $area,
        };
    }

    private static function mapUrl(string $coordinates): ?string
    {
        if (! preg_match('/^\s*([-+]?\d+(?:\.\d+)?)\s*,\s*([-+]?\d+(?:\.\d+)?)\s*$/', $coordinates, $matches)) {
            return null;
        }

        if (abs((float) $matches[1]) > 90 || abs((float) $matches[2]) > 180) {
            return null;
        }

        return 'https://www.google.com/maps/search/?api=1&query='.rawurlencode($matches[1].','.$matches[2]);
    }
}
