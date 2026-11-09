<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\BannerResource;
use App\Models\Banner;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class BannerController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'position' => ['nullable', 'string', Rule::in(array_keys(Banner::POSITIONS))],
        ]);

        return BannerResource::collection(
            Banner::query()
                ->currentlyVisible()
                ->when($request->filled('position'), fn ($query) => $query->where('position', $request->input('position')))
                ->whereHas('media', fn ($query) => $query->where('collection_name', 'image'))
                ->with('media')
                ->orderBy('sort_order')
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->get(),
        )->additional([
            'success' => true,
            'message' => 'Daftar banner berhasil diambil.',
        ]);
    }
}
