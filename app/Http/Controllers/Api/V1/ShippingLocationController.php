<?php

namespace App\Http\Controllers\Api\V1;

use App\Contracts\ShippingLocationProvider;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ShippingLocationController extends Controller
{
    public function provinces(Request $request, ShippingLocationProvider $provider): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
        ]);

        return ApiResponse::success(
            data: ['locations' => $provider->provinces($validated['q'] ?? null)],
            message: 'Daftar provinsi berhasil diambil.',
        );
    }

    public function cities(Request $request, ShippingLocationProvider $provider): JsonResponse
    {
        $validated = $request->validate([
            'province_id' => ['nullable', 'string', 'max:100', 'required_without_all:province_name,city_name'],
            'province_name' => ['nullable', 'string', 'max:100', 'required_without_all:province_id,city_name'],
            'city_name' => ['nullable', 'string', 'max:100', 'required_without_all:province_id,province_name'],
        ]);

        return ApiResponse::success(
            data: ['locations' => $provider->cities(
                $validated['province_id'] ?? null,
                $validated['province_name'] ?? null,
                $validated['city_name'] ?? null,
            )],
            message: 'Daftar kota berhasil diambil.',
        );
    }

    public function districts(Request $request, ShippingLocationProvider $provider): JsonResponse
    {
        $validated = $request->validate([
            'city_id' => ['nullable', 'string', 'max:100', 'required_without:district_name'],
            'district_name' => ['nullable', 'string', 'max:100', 'required_without:city_id'],
        ]);

        return ApiResponse::success(
            data: ['locations' => $provider->districts(
                $validated['city_id'] ?? null,
                $validated['district_name'] ?? null,
            )],
            message: 'Daftar kecamatan berhasil diambil.',
        );
    }

    public function subDistricts(Request $request, ShippingLocationProvider $provider): JsonResponse
    {
        $validated = $request->validate([
            'district_id' => ['nullable', 'string', 'max:100', 'required_without_all:sub_district_name,postal_code'],
            'sub_district_name' => ['nullable', 'string', 'max:100', 'required_without_all:district_id,postal_code'],
            'postal_code' => ['nullable', 'string', 'max:10', 'required_without_all:district_id,sub_district_name'],
        ]);

        return ApiResponse::success(
            data: ['locations' => $provider->subDistricts(
                $validated['district_id'] ?? null,
                $validated['sub_district_name'] ?? null,
                $validated['postal_code'] ?? null,
            )],
            message: 'Daftar kelurahan/desa berhasil diambil.',
        );
    }

    public function resolve(Request $request, ShippingLocationProvider $provider): JsonResponse
    {
        $validated = $request->validate([
            'address' => ['nullable', 'string', 'max:1000', 'required_without:postal_code'],
            'postal_code' => ['nullable', 'string', 'max:10', 'required_without:address'],
        ]);

        return ApiResponse::success(
            data: ['location' => $provider->resolve($validated)],
            message: 'Alamat berhasil divalidasi.',
        );
    }
}
