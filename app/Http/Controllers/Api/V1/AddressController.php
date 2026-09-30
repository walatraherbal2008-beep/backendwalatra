<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreAddressRequest;
use App\Http\Requests\Api\V1\UpdateAddressRequest;
use App\Http\Resources\Api\V1\AddressResource;
use App\Models\Address;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Support\ApiResponse;

class AddressController extends Controller
{
    public function index(Request $request)
    {
        return AddressResource::collection(
            $request->user()->addresses()->latest('is_default')->latest()->get(),
        )->additional(['success' => true, 'message' => 'Daftar alamat berhasil diambil.']);
    }

    public function store(StoreAddressRequest $request): AddressResource
    {
        $address = DB::transaction(function () use ($request): Address {
            $user = $request->user();
            $isDefault = $request->boolean('is_default') || ! $user->addresses()->exists();

            if ($isDefault) {
                $user->addresses()->update(['is_default' => false]);
            }

            return $user->addresses()->create([
                ...$request->validated(),
                'is_default' => $isDefault,
            ]);
        });

        return (new AddressResource($address))->additional([
            'success' => true,
            'message' => 'Alamat berhasil ditambahkan.',
        ]);
    }

    public function update(UpdateAddressRequest $request, int $address): AddressResource
    {
        $record = $request->user()->addresses()->findOrFail($address);

        DB::transaction(function () use ($request, $record): void {
            if ($request->boolean('is_default')) {
                $request->user()->addresses()->update(['is_default' => false]);
            }

            $record->update($request->validated());
        });

        return (new AddressResource($record->fresh()))->additional([
            'success' => true,
            'message' => 'Alamat berhasil diperbarui.',
        ]);
    }

    public function destroy(Request $request, int $address): JsonResponse
    {
        $record = $request->user()->addresses()->findOrFail($address);
        $wasDefault = $record->is_default;
        $record->delete();

        if ($wasDefault) {
            $request->user()->addresses()->latest()->first()?->update(['is_default' => true]);
        }

        return ApiResponse::success(message: 'Alamat berhasil dihapus.');
    }
}
