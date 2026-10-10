<?php

namespace Tests\Unit;

use App\Models\ShippingSubsidyRule;
use App\Services\Shipping\ShippingCostCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShippingCostCalculatorTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_applies_the_most_specific_priority_rule_and_caps_at_provider_fee(): void
    {
        ShippingSubsidyRule::query()->create([
            'name' => 'Nasional',
            'scope_type' => 'all',
            'min_subtotal' => 0,
            'max_subsidy' => 5000,
            'priority' => 1,
            'enabled' => true,
        ]);
        $rule = ShippingSubsidyRule::query()->create([
            'name' => 'Jawa Barat',
            'scope_type' => 'province',
            'scope_code' => '32',
            'min_subtotal' => 100000,
            'max_subsidy' => 20000,
            'priority' => 10,
            'enabled' => true,
        ]);

        $result = (new ShippingCostCalculator)->calculate(
            ['fee' => 12000, 'service' => 'REG', 'eta_days' => 3],
            ['province_id' => '32'],
            150000,
            1000,
        );

        $this->assertSame($rule->id, $result['rule_id']);
        $this->assertSame(12000.0, $result['gross_fee']);
        $this->assertSame(12000.0, $result['subsidy']);
        $this->assertSame(0.0, $result['fee']);
    }

    public function test_it_does_not_apply_disabled_or_weight_limited_rules(): void
    {
        ShippingSubsidyRule::query()->create([
            'name' => 'Tidak berlaku',
            'scope_type' => 'all',
            'max_subsidy' => 50000,
            'max_weight_grams' => 500,
            'enabled' => false,
        ]);

        $result = (new ShippingCostCalculator)->calculate(
            ['fee' => 12000, 'service' => 'REG', 'eta_days' => 3],
            [],
            100000,
            1000,
        );

        $this->assertSame(0.0, $result['subsidy']);
        $this->assertSame(12000.0, $result['fee']);
    }
}
