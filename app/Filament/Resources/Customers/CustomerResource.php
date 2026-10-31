<?php

namespace App\Filament\Resources\Customers;

use App\Filament\Resources\Customers\Pages\ManageCustomers;
use App\Filament\Resources\Customers\Pages\EditCustomer;
use App\Filament\Resources\Customers\RelationManagers\AddressesRelationManager;
use App\Filament\Resources\Customers\RelationManagers\OrdersRelationManager;
use App\Filament\Resources\Customers\RelationManagers\StatusHistoriesRelationManager;
use App\Filament\Resources\Customers\RelationManagers\TokensRelationManager;
use App\Filament\Resources\Customers\RelationManagers\LoginHistoriesRelationManager;
use App\Models\User;
use App\Services\Marketplace\UserStatusService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class CustomerResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static string|UnitEnum|null $navigationGroup = 'Manajemen Marketplace';

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationLabel = 'Customer';

    protected static ?string $modelLabel = 'customer';

    protected static ?string $pluralModelLabel = 'customer';

    public static function canAccess(): bool
    {
        return auth()->user()?->hasRole('super_admin') ?? false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('user_type', 'customer')
            ->withCount(['orders', 'addresses']);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->label('Nama customer')
                ->required()
                ->maxLength(255),
            TextInput::make('phone')
                ->label('Nomor WhatsApp')
                ->tel()
                ->disabled()
                ->dehydrated(false),
            TextInput::make('email')
                ->label('Email')
                ->email()
                ->nullable()
                ->unique(ignoreRecord: true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name')->label('Nama')->searchable()->sortable(),
                TextColumn::make('phone')->label('WhatsApp')->searchable(),
                TextColumn::make('email')->searchable()->placeholder('-'),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'active' => 'Aktif',
                        'suspended' => 'Suspended',
                        'blocked' => 'Diblokir',
                        'inactive' => 'Nonaktif',
                        default => ucfirst($state),
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'active' => 'success',
                        'suspended' => 'warning',
                        'blocked' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('addresses_count')->label('Alamat')->sortable(),
                TextColumn::make('orders_count')->label('Pesanan')->sortable(),
                TextColumn::make('created_at')->label('Terdaftar')->dateTime('d M Y H:i')->sortable(),
                TextColumn::make('last_login_at')->label('Login terakhir')->dateTime('d M Y H:i')->placeholder('-')->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->label('Status')->options([
                    'active' => 'Aktif',
                    'suspended' => 'Suspended',
                    'blocked' => 'Diblokir',
                    'inactive' => 'Nonaktif',
                ]),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                EditAction::make(),
                self::statusAction('suspend', 'Suspend', 'warning', 'suspended', 'Tangguhkan customer'),
                self::statusAction('block', 'Blokir', 'danger', 'blocked', 'Blokir customer'),
                self::statusAction('activate', 'Aktifkan kembali', 'success', 'active', 'Aktifkan kembali customer'),
            ])
            ->toolbarActions([]);
    }

    public static function getRelations(): array
    {
        return [
            AddressesRelationManager::class,
            OrdersRelationManager::class,
            StatusHistoriesRelationManager::class,
            TokensRelationManager::class,
            LoginHistoriesRelationManager::class,
        ];
    }

    private static function statusAction(
        string $name,
        string $label,
        string $color,
        string $status,
        string $title,
    ): Action {
        return Action::make($name)
            ->label($label)
            ->color($color)
            ->icon($status === 'active' ? Heroicon::OutlinedCheckCircle : Heroicon::OutlinedExclamationTriangle)
            ->requiresConfirmation()
            ->modalHeading($title)
            ->form([
                Textarea::make('reason')
                    ->label('Alasan')
                    ->required()
                    ->minLength(5)
                    ->maxLength(1000),
            ])
            ->visible(fn (User $record): bool => $status === 'active'
                ? $record->status !== 'active'
                : $record->status === 'active' || ($status === 'blocked' && $record->status === 'suspended'))
            ->action(function (User $record, array $data) use ($status, $label): void {
                app(UserStatusService::class)->change(
                    user: $record,
                    status: $status,
                    reason: $data['reason'],
                    changedBy: auth()->user(),
                );

                Notification::make()->title("Customer berhasil {$label}. ")->success()->send();
            });
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageCustomers::route('/'),
            'edit' => EditCustomer::route('/{record}/edit'),
        ];
    }
}
