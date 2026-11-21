<?php

namespace App\Filament\Resources\ApiRequestAuditLogs;

use App\Filament\Resources\ApiRequestAuditLogs\Pages\ListApiRequestAuditLogs;
use App\Models\ApiRequestAuditLog;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Infolists\Components\CodeEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class ApiRequestAuditLogResource extends Resource
{
    protected static ?string $model = ApiRequestAuditLog::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentMagnifyingGlass;

    protected static string|UnitEnum|null $navigationGroup = 'Sistem';

    protected static ?int $navigationSort = 30;

    protected static ?string $navigationLabel = 'Audit Request API';

    protected static ?string $modelLabel = 'request API';

    protected static ?string $pluralModelLabel = 'Audit Request API';

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

    public static function getEloquentQuery(): Builder
    {
        abort_unless(self::canAccess(), 403);

        return parent::getEloquentQuery()->select([
            'id', 'request_id', 'http_method', 'endpoint', 'route_name', 'status_code', 'duration_ms',
            'ip_address', 'actor_type', 'actor_id', 'authentication_status', 'started_at',
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->heading('Request masuk ke API backend')
            ->description('Audit semua request /api/*, termasuk request yang gagal. Data diperbarui otomatis setiap 5 detik.')
            ->poll('5s')
            ->defaultSort('started_at', 'desc')
            ->columns([
                TextColumn::make('row_number')->label('No.')->rowIndex()->alignCenter(),
                TextColumn::make('started_at')->label('Waktu (WIB)')->dateTime('d M Y H:i:s', timezone: 'Asia/Jakarta')->sortable(),
                TextColumn::make('request_id')->label('ID request')->searchable()->copyable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('actor_id')->label('Aktor')->formatStateUsing(fn ($state, ApiRequestAuditLog $record): string => $record->actor_type
                    ? class_basename($record->actor_type).' #'.$record->actor_id
                    : 'Publik / belum login')->searchable(),
                TextColumn::make('http_method')->label('Metode')->badge(),
                TextColumn::make('endpoint')->label('Endpoint')->searchable()->wrap()->limit(60),
                TextColumn::make('status_code')->label('HTTP')->badge()->color(fn (?int $state): string => match (true) {
                    $state !== null && $state < 300 => 'success',
                    $state !== null && $state < 400 => 'info',
                    $state !== null && $state < 500 => 'warning',
                    default => 'danger',
                }),
                TextColumn::make('duration_ms')->label('Durasi')->suffix(' ms')->sortable(),
                TextColumn::make('ip_address')->label('IP')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status_code')->label('Status HTTP')->options([
                    200 => '200 Berhasil', 201 => '201 Dibuat', 202 => '202 Diterima', 204 => '204 Tanpa konten',
                    301 => '301 Dialihkan', 302 => '302 Dialihkan', 400 => '400 Request salah', 401 => '401 Belum login',
                    403 => '403 Tidak diizinkan', 404 => '404 Tidak ditemukan', 409 => '409 Konflik', 413 => '413 Terlalu besar',
                    422 => '422 Validasi', 429 => '429 Rate limit', 500 => '500 Server error', 502 => '502 Gateway', 503 => '503 Layanan tidak tersedia',
                ]),
                SelectFilter::make('http_method')->label('Metode')->options([
                    'GET' => 'GET', 'POST' => 'POST', 'PUT' => 'PUT', 'PATCH' => 'PATCH', 'DELETE' => 'DELETE', 'OPTIONS' => 'OPTIONS',
                ]),
                SelectFilter::make('authentication_status')->label('Autentikasi')->options([
                    'authenticated' => 'Terautentikasi', 'guest' => 'Publik / belum login',
                ]),
                SelectFilter::make('actor_type')->label('Jenis aktor')->options(fn (): array => ApiRequestAuditLog::query()
                    ->whereNotNull('actor_type')->distinct()->pluck('actor_type')
                    ->mapWithKeys(fn (string $type): array => [$type => class_basename($type)])->all()),
                Filter::make('failed')->label('Request gagal (4xx/5xx)')
                    ->query(fn (Builder $query): Builder => $query->where('status_code', '>=', 400)),
                Filter::make('slow')->label('Lambat (≥ 1 detik)')
                    ->query(fn (Builder $query): Builder => $query->where('duration_ms', '>=', 1000)),
                Filter::make('started_at')->label('Rentang waktu')->schema([
                    DatePicker::make('from')->label('Dari tanggal')->native(false),
                    DatePicker::make('until')->label('Sampai tanggal')->native(false)->afterOrEqual('from'),
                ])->query(fn (Builder $query, array $data): Builder => $query
                    ->when($data['from'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('started_at', '>=', $date))
                    ->when($data['until'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('started_at', '<=', $date))),
            ])
            ->deferFilters(false)
            ->recordActions([
                ViewAction::make()->label('Detail')->iconButton()->color('gray')->slideOver()->modalWidth('4xl')
                    ->authorize(fn (): bool => self::canAccess()),
            ])
            ->recordActionsColumnLabel('Detail')
            ->toolbarActions([]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Ringkasan request')->columnSpanFull()->columns(['default' => 1, 'md' => 2])->schema([
                TextEntry::make('request_id')->label('ID request')->copyable()->columnSpanFull(),
                TextEntry::make('started_at')->label('Waktu (WIB)')->dateTime('d M Y H:i:s', timezone: 'Asia/Jakarta'),
                TextEntry::make('http_method')->label('Metode HTTP'),
                TextEntry::make('endpoint')->label('Endpoint')->columnSpanFull()->copyable(),
                TextEntry::make('route_name')->label('Nama route')->placeholder('Tidak ada route cocok'),
                TextEntry::make('status_code')->label('Status HTTP'),
                TextEntry::make('duration_ms')->label('Durasi')->suffix(' ms'),
                TextEntry::make('authentication_status')->label('Autentikasi'),
                TextEntry::make('actor_type')->label('Jenis aktor')->placeholder('Publik / belum login'),
                TextEntry::make('actor_id')->label('ID aktor')->placeholder('-'),
                TextEntry::make('ip_address')->label('Alamat IP')->placeholder('-'),
                TextEntry::make('user_agent')->label('User agent')->columnSpanFull()->placeholder('-'),
                TextEntry::make('request_content_type')->label('Content-Type request')->placeholder('-'),
                TextEntry::make('response_content_type')->label('Content-Type response')->placeholder('-'),
                TextEntry::make('request_bytes')->label('Ukuran request')->suffix(' byte'),
                TextEntry::make('response_bytes')->label('Ukuran response')->suffix(' byte'),
                TextEntry::make('exception_type')->label('Exception')->placeholder('—')->columnSpanFull(),
            ]),
            Section::make('Payload yang sudah disaring')->columnSpanFull()->schema([
                CodeEntry::make('query_keys')->label('Parameter query (nama field saja)')->jsonFlags(JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)->placeholder('Tidak ada parameter query.'),
                CodeEntry::make('request_payload')->label('Request')->jsonFlags(JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)->placeholder('Payload tidak tersedia atau tidak dicatat.'),
                CodeEntry::make('response_payload')->label('Response JSON')->jsonFlags(JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)->placeholder('Response JSON tidak tersedia atau terlalu besar.'),
            ]),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListApiRequestAuditLogs::route('/')];
    }
}
