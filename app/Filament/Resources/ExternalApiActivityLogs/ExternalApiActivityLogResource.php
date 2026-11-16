<?php

namespace App\Filament\Resources\ExternalApiActivityLogs;

use App\Filament\Resources\ExternalApiActivityLogs\Pages\ListExternalApiActivityLogs;
use App\Models\ExternalApiActivityLog;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\CodeEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Actions\HeaderActionsPosition;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class ExternalApiActivityLogResource extends Resource
{
    protected static ?string $model = ExternalApiActivityLog::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSignal;

    protected static string|UnitEnum|null $navigationGroup = 'Manajemen Marketplace';

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationLabel = 'Monitoring API Eksternal';

    protected static ?string $modelLabel = 'aktivitas API eksternal';

    protected static ?string $pluralModelLabel = 'Monitoring API Eksternal';

    public static function canAccess(): bool
    {
        return auth()->user()?->status === 'active' && auth()->user()->hasRole('super_admin');
    }

    public static function canViewAny(): bool
    {
        return self::canAccess();
    }

    public static function canView(Model $record): bool
    {
        return self::canAccess();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function serviceConfigurations(): array
    {
        return [
            'midtrans' => ['label' => 'Midtrans', 'enabled' => filled(config('services.midtrans.server_key'))],
            'everpro' => ['label' => 'Everpro', 'enabled' => config('services.everpro.enabled') && filled(config('services.everpro.client_key')) && filled(config('services.everpro.client_secret'))],
            'maxchat' => ['label' => 'Maxchat', 'enabled' => config('services.maxchat.enabled') && filled(config('services.maxchat.api_token'))],
            'warehouse_directory' => ['label' => 'Titik Pengiriman', 'enabled' => config('services.warehouse_directory.enabled') && filled(config('services.warehouse_directory.base_url'))],
            'warehouse_stock' => ['label' => 'Stok Gudang', 'enabled' => config('services.warehouse.enabled') && filled(config('services.warehouse.base_url')) && filled(config('services.warehouse.token'))],
            'shipping_rate' => ['label' => 'Tarif Ongkir', 'enabled' => config('services.shipping.enabled') && filled(config('services.shipping.base_url')) && filled(config('services.shipping.token'))],
        ];
    }

    public static function serviceLabels(): array
    {
        return collect(self::serviceConfigurations())->map(fn (array $service): string => $service['label'])->all();
    }

    public static function getEloquentQuery(): Builder
    {
        abort_unless(self::canAccess(), 403);

        return parent::getEloquentQuery()->select([
            'id', 'service', 'operation', 'http_method', 'endpoint', 'correlation_id', 'status',
            'http_status', 'duration_ms', 'error_type', 'error_message', 'started_at', 'finished_at',
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->heading('Aktivitas API')
            ->description('Data diperbarui otomatis setiap 5 detik dari aktivitas yang dicatat backend.')
            ->poll('5s')
            ->defaultSort('started_at', 'desc')
            ->columns([
                TextColumn::make('row_number')->label('No.')->rowIndex()->alignCenter(),
                TextColumn::make('started_at')->label('Waktu (WIB)')->dateTime('d M Y H:i:s', timezone: 'Asia/Jakarta')->sortable(),
                TextColumn::make('service')->label('API')->badge()
                    ->formatStateUsing(fn (string $state): string => self::serviceLabels()[$state] ?? $state),
                TextColumn::make('operation')->label('Operasi')->searchable()->wrap(),
                TextColumn::make('http_method')->label('Metode')->badge(),
                TextColumn::make('status')->label('Status')->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'succeeded' => 'success',
                        'running' => 'info',
                        'timeout', 'failed' => 'danger',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'succeeded' => 'Berhasil',
                        'running' => 'Sedang berjalan',
                        'timeout' => 'Timeout',
                        'failed' => 'Gagal',
                        default => $state,
                    }),
                TextColumn::make('http_status')->label('HTTP')->placeholder('—'),
                TextColumn::make('duration_ms')->label('Durasi')->suffix(' ms')->placeholder('—'),
                TextColumn::make('endpoint')->label('Endpoint')->limit(55)->tooltip(fn (TextColumn $column): ?string => $column->getState())->copyable()->wrap(),
            ])
            ->headerActions([
                Action::make('checkApiConnections')
                    ->label('Uji koneksi API')
                    ->icon(Heroicon::OutlinedSignal)
                    ->color('gray')
                    ->requiresConfirmation()
                    ->modalHeading(fn (ListExternalApiActivityLogs $livewire): string => $livewire->connectionCheckTitle())
                    ->modalDescription('Pemeriksaan memakai request baca atau autentikasi. Tidak membuat transaksi, mengirim OTP, atau mengubah stok. API nonaktif akan dilewati.')
                    ->modalSubmitActionLabel('Mulai pemeriksaan')
                    ->authorize(fn (): bool => self::canAccess())
                    ->action(fn (ListExternalApiActivityLogs $livewire) => $livewire->runConnectionCheck()),
            ], HeaderActionsPosition::Adaptive)
            ->filters([
                SelectFilter::make('status')->label('Status')->options([
                    'running' => 'Sedang berjalan',
                    'succeeded' => 'Berhasil',
                    'failed' => 'Gagal',
                    'timeout' => 'Timeout',
                ]),
            ])
            ->deferFilters(false)
            ->recordAction('details')
            ->recordActions([
                ViewAction::make('details')
                    ->label('Detail')
                    ->iconButton()
                    ->color('gray')
                    ->slideOver()
                    ->modalWidth('4xl')
                    ->authorize(fn (): bool => self::canAccess()),
            ])
            ->recordActionsColumnLabel('Detail')
            ->toolbarActions([]);
    }

    public static function infolist(Schema $schema): Schema
    {
        $schema->getRecord()?->refresh();

        return $schema->components([
            Section::make('Ringkasan request')->columnSpanFull()->columns(['default' => 1, 'md' => 2])->schema([
                TextEntry::make('service')->label('API')->formatStateUsing(fn (string $state): string => self::serviceLabels()[$state] ?? $state),
                TextEntry::make('operation')->label('Operasi'),
                TextEntry::make('http_method')->label('Metode HTTP'),
                TextEntry::make('http_status')->label('Status HTTP')->placeholder('Belum ada respons'),
                TextEntry::make('status')->label('Status aktivitas'),
                TextEntry::make('duration_ms')->label('Durasi')->suffix(' ms')->placeholder('Masih berjalan'),
                TextEntry::make('started_at')->label('Dimulai')->dateTime('d M Y H:i:s', timezone: 'Asia/Jakarta'),
                TextEntry::make('finished_at')->label('Selesai')->dateTime('d M Y H:i:s', timezone: 'Asia/Jakarta')->placeholder('Masih berjalan'),
                TextEntry::make('endpoint')->label('Endpoint')->columnSpanFull()->copyable(),
                TextEntry::make('correlation_id')->label('ID pelacakan')->columnSpanFull()->copyable(),
                TextEntry::make('error_type')->label('Jenis kegagalan')->placeholder('—'),
                TextEntry::make('error_message')->label('Keterangan')->placeholder('—')->columnSpanFull(),
            ]),
            Section::make('Payload yang sudah disaring')->columnSpanFull()->schema([
                CodeEntry::make('request_payload')->label('Request')->jsonFlags(JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)->placeholder('Tidak ada payload.'),
                CodeEntry::make('response_payload')->label('Response')->jsonFlags(JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)->placeholder('Belum ada response JSON.'),
            ]),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListExternalApiActivityLogs::route('/')];
    }
}
