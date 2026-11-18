<?php

namespace Tests\Feature;

use App\Support\ProductionSecurity;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class ProductionSecurityTest extends TestCase
{
    public static function unsafeConfigurations(): array
    {
        return [
            'debug enabled' => ['app.debug', true],
            'OTP exposed' => ['services.otp.expose_code', true],
            'insecure cookie' => ['session.secure', false],
            'cookie unspecified' => ['session.secure', null],
            'bridge missing' => ['security.bridge_secret', ''],
            'bridge too short' => ['security.bridge_secret', str_repeat('s', 31)],
        ];
    }

    #[DataProvider('unsafeConfigurations')]
    public function test_production_rejects_each_unsafe_setting(string $setting, mixed $value): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        config(['app.debug' => false, 'services.otp.expose_code' => false, 'session.secure' => true, 'security.bridge_secret' => str_repeat('s', 32)]);
        config([$setting => $value]);
        $this->expectException(RuntimeException::class);
        ProductionSecurity::check();
    }

    public function test_secure_production_configuration_is_accepted(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        config(['app.debug' => false, 'services.otp.expose_code' => false, 'session.secure' => true, 'security.bridge_secret' => str_repeat('s', 32)]);
        ProductionSecurity::check();
        $this->assertTrue(true);
    }

    public function test_local_environment_can_boot_without_production_settings(): void
    {
        $this->app->detectEnvironment(fn () => 'local');
        config(['app.debug' => true, 'services.otp.expose_code' => true, 'session.secure' => false, 'security.bridge_secret' => '']);
        ProductionSecurity::check();
        $this->assertTrue(true);
    }
}
