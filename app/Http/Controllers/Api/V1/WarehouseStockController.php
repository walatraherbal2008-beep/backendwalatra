<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Warehouse;
use App\Services\Warehouse\WarehouseRemainingStockClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WarehouseStockController extends Controller
{
    public function show(Request $request, string $code, WarehouseRemainingStockClient $stock): JsonResponse
    {
        $validated = $request->validate([
            'product_code' => ['required', 'string', 'max:100'],
            'quantity' => ['required', 'integer', 'min:1', 'max:2147483647'],
        ]);
        $warehouse = Warehouse::active()->where('code', $code)->firstOrFail();
        $product = Product::where('product_code', $validated['product_code'])->where('status', 'active')->firstOrFail();
        $reportedQuantity = $stock->quantity($warehouse->code, $product->product_code);
        $availableQuantity = max(0, $reportedQuantity);

        return response()->json([
            'success' => true, 'message' => 'Sisa stok berhasil diperiksa. Stok belum direservasi.',
            'data' => [
                'warehouse_code' => $warehouse->code, 'product_code' => $product->product_code,
                'requested_quantity' => (int) $validated['quantity'], 'reported_quantity' => $reportedQuantity,
                'available_quantity' => $availableQuantity,
                'sufficient' => $availableQuantity >= $validated['quantity'], 'reserved' => false,
                'checked_at' => now()->toIso8601String(),
            ],
        ]);
    }
}
