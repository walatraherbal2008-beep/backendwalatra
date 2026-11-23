<?php

namespace Tests\Audit;

class DiagnosedCartConcurrencyTest extends \Tests\Integration\CartCheckoutConcurrencyTest
{
    protected function setUp(): void
    {
        parent::setUp();
        // Match the WH-TASIK fixture instead of the developer's selected warehouse.
        config(['checkout.preferred_warehouse_code' => null, 'checkout.warehouse_selection' => 'legacy']);
    }

    public function json($method, $uri, array $data = [], array $headers = [], $options = 0)
    {
        $response = parent::json($method, $uri, $data, $headers, $options);
        if ($response->status() >= 400) {
            fwrite(STDOUT, "\n".json_encode([
                'diagnostic' => 'cart_concurrency_response', 'uri' => $uri, 'status' => $response->status(),
                'code' => $response->json('code'), 'message' => $response->json('message'),
                'selection' => config('checkout.warehouse_selection'),
                'preferred_warehouse' => config('checkout.preferred_warehouse_code'),
            ])."\n");
        }

        return $response;
    }
}
