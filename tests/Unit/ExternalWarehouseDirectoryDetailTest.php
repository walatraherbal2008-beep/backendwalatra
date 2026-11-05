<?php

namespace Tests\Unit;

use App\Exceptions\WarehouseDirectoryUnavailableException;
use App\Services\Warehouse\ExternalWarehouseDirectoryClient;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ExternalWarehouseDirectoryDetailTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.warehouse_directory.enabled' => true,
            'services.warehouse_directory.base_url' => 'https://warehouse.example.test',
            'services.warehouse_directory.detail_path' => '/index.php?procs=gudang.detail',
        ]);
        Http::preventStrayRequests();
    }

    public function test_detail_posts_code_as_url_encoded_form_and_normalizes_missing_optional_fields(): void
    {
        Http::fake(['*' => Http::response(['error' => '', 'data' => [
            'codename' => 'BANDUNG', 'realname' => 'Bandung', 'fulladdr' => ' Alamat Bandung ', 'coordinates' => null,
        ]])]);
        $detail = app(ExternalWarehouseDirectoryClient::class)->detail('Opaque+Code=');
        $this->assertSame('Alamat Bandung', $detail['fulladdr']);
        $this->assertSame('', $detail['coordinates']);
        $this->assertSame('', $detail['postal_code']);
        Http::assertSent(fn ($request): bool => $request->method() === 'POST'
            && $request->url() === 'https://warehouse.example.test/index.php?procs=gudang.detail'
            && $request->hasHeader('Content-Type', 'application/x-www-form-urlencoded')
            && $request['code'] === 'Opaque+Code='
            && $request->body() === 'code=Opaque%2BCode%3D');
    }

    public static function invalidResponses(): array
    {
        return [
            'HTTP failure' => [['error' => 'Unavailable'], 503],
            'upstream error' => [['error' => 'Gudang tidak ditemukan', 'data' => []], 200],
            'empty detail with HTTP 200' => [['error' => '', 'data' => ['codename' => '', 'realname' => '', 'fulladdr' => '']], 200],
            'invalid field type' => [['error' => '', 'data' => ['codename' => 'BDG', 'realname' => 'Bandung', 'fulladdr' => ['invalid']]], 200],
        ];
    }

    #[DataProvider('invalidResponses')]
    public function test_invalid_detail_responses_raise_service_exception(array $payload, int $status): void
    {
        Http::fake(['*' => Http::response($payload, $status)]);
        $this->expectException(WarehouseDirectoryUnavailableException::class);
        app(ExternalWarehouseDirectoryClient::class)->detail('WH-BDG');
    }
}
