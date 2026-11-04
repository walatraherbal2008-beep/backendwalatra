<?php

namespace App\Filament\Resources\Products\RelationManagers;

use App\Contracts\WarehouseDirectoryProvider;
use App\Exceptions\WarehouseDirectoryUnavailableException;
use App\Models\ProductVariant;
use App\Services\Warehouse\WarehouseProductAssignmentService;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Exceptions\Halt;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class VariantsRelationManager extends RelationManager
{
    protected static string $relationship = 'variants';

    protected static ?string $title = 'Varian Produk';

    private ?array $warehouseNames = null;

    private function warehouseNames(): array
    {
        if ($this->warehouseNames !== null) {
            return $this->warehouseNames;
        }

        try {
            return $this->warehouseNames = collect(app(WarehouseDirectoryProvider::class)->list())
                ->pluck('realname', 'code')->all();
        } catch (WarehouseDirectoryUnavailableException) {
            return $this->warehouseNames = [];
        }
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('Nama varian')
                    ->required()
                    ->maxLength(255)
                    ->helperText('Nama varian tidak boleh sama dalam satu produk.'),
                TextInput::make('sku')
                    ->label('SKU otomatis')
                    ->disabled()
                    ->dehydrated(false)
                    ->helperText('SKU dibuat otomatis saat varian disimpan.'),
                TextInput::make('price')
                    ->label('Harga dasar')
                    ->numeric()
                    ->prefix('Rp')
                    ->minValue(0.01)
                    ->required(),
                TextInput::make('weight_grams')
                    ->label('Berat (gram)')
                    ->numeric()
                    ->minValue(1)
                    ->required(),
                Select::make('status')
                    ->label('Status')
                    ->options([
                        'active' => 'Aktif',
                        'inactive' => 'Tidak aktif',
                    ])
                    ->default('active')
                    ->required(),
                Select::make('warehouse_codes')
                    ->label('Gudang penyedia')
                    ->visible(fn (string $operation): bool => $operation === 'edit')
                    ->multiple()
                    ->searchable()
                    ->preload()
                    ->options(function (): array {
                        try {
                            return collect(app(WarehouseDirectoryProvider::class)->list())
                                ->mapWithKeys(fn (array $warehouse): array => [
                                    $warehouse['code'] => $warehouse['realname'].' ('.$warehouse['codename'].')',
                                ])->all();
                        } catch (WarehouseDirectoryUnavailableException) {
                            Notification::make()->danger()->title('Daftar gudang belum dapat dimuat')
                                ->body('Periksa koneksi API gudang, lalu buka kembali pengaturan.')->send();

                            return [];
                        }
                    })
                    ->helperText('Berlaku untuk seluruh toko. Pilih beberapa gudang atau kosongkan untuk menghapus penetapan varian ini.'),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('warehouseAssignments'))
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name')
                    ->label('Nama varian')
                    ->searchable(),
                TextColumn::make('sku')
                    ->label('SKU')
                    ->searchable(),
                TextColumn::make('price')
                    ->label('Harga')
                    ->numeric(decimalPlaces: 0, decimalSeparator: '.', thousandsSeparator: '')
                    ->prefix('Rp '),
                TextColumn::make('weight_grams')
                    ->label('Berat')
                    ->suffix(' gram'),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge(),
                TextColumn::make('warehouse_names')
                    ->label('Gudang penyedia')
                    ->state(function (ProductVariant $record): array {
                        if ($record->warehouseAssignments->isEmpty()) {
                            return [];
                        }

                        $names = $this->warehouseNames();

                        return $record->warehouseAssignments
                            ->sortBy('id')
                            ->map(fn ($assignment): ?string => $names[$assignment->warehouse_code] ?? null)
                            ->filter(fn (?string $name): bool => filled($name))
                            ->take(3)
                            ->values()->all();
                    })
                    ->badge(),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Tambah Varian'),
            ])
            ->recordActions([
                EditAction::make()
                    ->authorize(fn (): bool => auth()->user()?->status === 'active' && auth()->user()->hasRole('super_admin'))
                    ->mutateRecordDataUsing(fn (array $data, ProductVariant $record): array => [
                        ...$data,
                        'warehouse_codes' => $record->warehouseAssignments()->pluck('warehouse_code')->all(),
                    ])
                    ->using(function (ProductVariant $record, array $data): ProductVariant {
                        $warehouseCodes = $data['warehouse_codes'] ?? [];
                        unset($data['warehouse_codes']);

                        try {
                            return DB::transaction(function () use ($record, $data, $warehouseCodes): ProductVariant {
                                app(WarehouseProductAssignmentService::class)->sync($record, $warehouseCodes);
                                $record->update($data);

                                return $record;
                            });
                        } catch (ValidationException $exception) {
                            $errors = [];
                            foreach ($exception->errors() as $field => $messages) {
                                $errors[$this->getMountedActionSchema()->getStatePath().'.'.$field] = $messages;
                            }
                            throw ValidationException::withMessages($errors);
                        } catch (WarehouseDirectoryUnavailableException) {
                            Notification::make()->danger()->title('Perubahan varian belum disimpan')
                                ->body('API gudang tidak tersedia. Data varian dan pengaturan gudang sebelumnya tetap tersimpan.')->send();

                            throw new Halt;
                        }
                    }),
                DeleteAction::make()
                    ->label('Hapus')
                    ->requiresConfirmation(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
