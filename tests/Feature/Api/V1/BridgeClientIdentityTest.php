<?php

namespace Tests\Feature\Api\V1;

use App\Support\ClientIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class BridgeClientIdentityTest extends TestCase
{
    use RefreshDatabase;

    private function signed(string $ip, string $path = '/api/v1/auth/customer/request-otp', ?int $time = null): array
    {
        $time ??= time();

        return ['X-Walatra-Client-IP' => $ip, 'X-Walatra-Client-Time' => (string) $time,
            'X-Walatra-Client-Signature' => hash_hmac('sha256', implode("\n", [$time, $ip, 'POST', $path]), str_repeat('s', 32))];
    }

    public function test_signed_clients_have_independent_otp_budgets_and_forgery_is_ignored(): void
    {
        config(['security.bridge_secret' => str_repeat('s', 32), 'security.otp_request_ip_limit' => 1]);
        $path = '/api/v1/auth/customer/request-otp';
        $this->postJson($path, ['phone' => '08123456001'], $this->signed('192.0.2.10'))->assertOk();
        $this->postJson($path, ['phone' => '08123456002'], $this->signed('192.0.2.20'))->assertOk();
        $this->postJson($path, ['phone' => '08123456003'], $this->signed('192.0.2.10'))->assertTooManyRequests();
        $forged = array_replace($this->signed('192.0.2.20'), ['X-Walatra-Client-IP' => '192.0.2.30']);
        $this->postJson($path, ['phone' => '08123456004'], $forged)->assertOk();
        $forged['X-Walatra-Client-IP'] = '192.0.2.40';
        $this->postJson($path, ['phone' => '08123456005'], $forged)->assertTooManyRequests();
    }

    public function test_expired_and_wrong_path_signatures_cannot_set_client_identity(): void
    {
        config(['security.bridge_secret' => str_repeat('s', 32)]);
        foreach ([$this->signed('192.0.2.10', time: time() - 60), $this->signed('192.0.2.10', '/other')] as $headers) {
            $request = Request::create('/api/v1/auth/customer/request-otp', 'POST', server: ['REMOTE_ADDR' => '127.0.0.1']);
            $request->headers->add($headers);
            $this->assertSame('127.0.0.1', ClientIdentity::ip($request));
        }
    }

    public function test_wrong_method_future_timestamp_and_malformed_headers_use_connection_ip(): void
    {
        config(['security.bridge_secret' => str_repeat('s', 32)]);
        $headers = $this->signed('192.0.2.10');
        $invalidHeaders = [
            $this->signed('192.0.2.10', time: time() + 60),
            array_replace($headers, ['X-Walatra-Client-IP' => 'invalid']),
            array_replace($headers, ['X-Walatra-Client-Time' => 'invalid']),
            array_replace($headers, ['X-Walatra-Client-Signature' => 'invalid']),
            [],
        ];
        foreach ($invalidHeaders as $invalid) {
            $request = Request::create('/api/v1/auth/customer/request-otp', 'POST', server: ['REMOTE_ADDR' => '127.0.0.1']);
            $request->headers->add($invalid);
            $this->assertSame('127.0.0.1', ClientIdentity::ip($request));
        }
        $request = Request::create('/api/v1/auth/customer/request-otp', 'GET', server: ['REMOTE_ADDR' => '127.0.0.1']);
        $request->headers->add($headers);
        $this->assertSame('127.0.0.1', ClientIdentity::ip($request));
    }
}
