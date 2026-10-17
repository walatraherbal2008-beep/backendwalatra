<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\BannerResource;
use App\Models\Banner;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class BannerController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return BannerResource::collection(
            Banner::query()
                ->currentlyVisible()
                ->with('media')
                ->orderBy('sort_order')
                ->orderByDesc('created_at')
                ->get(),
        )->additional([
            'success' => true,
            'message' => 'Daftar banner berhasil diambil.',
        ]);
    }
}
