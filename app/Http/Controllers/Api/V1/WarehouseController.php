<?php

namespace App\Http\Controllers\Api\V1;

use App\Contracts\WarehouseDirectoryProvider;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WarehouseController extends Controller
{
    public function index(WarehouseDirectoryProvider $provider): JsonResponse
    {
        $warehouses = $provider->list();

        return ApiResponse::success(
            data: $warehouses,
            message: 'Daftar seluruh gudang berhasil diambil.',
            meta: ['total' => count($warehouses)],
        );
    }

    public function group(Request $request, WarehouseDirectoryProvider $provider): JsonResponse
    {
        $validated = $request->validate([
            'area_group' => ['required', 'string', 'in:pulaujawa,luarjawa'],
        ]);

        $warehouses = $provider->listByGroup($validated['area_group']);

        return ApiResponse::success(
            data: $warehouses,
            message: 'Daftar gudang berdasarkan group berhasil diambil.',
            meta: ['total' => count($warehouses)],
        );
    }
}
