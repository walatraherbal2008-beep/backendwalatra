<?php

namespace App\Services\Shipping;

use App\Models\ShippingSubsidyRule;

class ShippingCostCalculator
{
    /**
     * @return array{gross_fee: float, subsidy: float, fee: float, rule_id: int|null}
     */
    public function calculate(array $quote, array $address, float $subtotal, int $weightGrams = 0): array
    {
        $grossFee = max(0, (float) ($quote['fee'] ?? 0));
        $rule = $this->matchingRule($address, $subtotal, $weightGrams);
        $subsidy = $rule ? min($grossFee, max(0, (float) $rule->max_subsidy)) : 0.0;

        return [
            'gross_fee' => $grossFee,
            'subsidy' => $subsidy,
            'fee' => (float) max(0.0, $grossFee - $subsidy),
            'rule_id' => $rule?->id,
        ];
    }

    public function matchingRule(array $address, float $subtotal, int $weightGrams = 0): ?ShippingSubsidyRule
    {
        $now = now();
        $province = (string) ($address['province_id'] ?? '');
        $city = (string) ($address['city_id'] ?? '');
        $district = (string) ($address['district_id'] ?? '');
        $subDistrict = (string) ($address['sub_district_id'] ?? '');

        return ShippingSubsidyRule::query()
            ->where('enabled', true)
            ->where('min_subtotal', '<=', $subtotal)
            ->where(function ($query) use ($now): void {
                $query->whereNull('starts_at')->orWhere('starts_at', '<=', $now);
            })
            ->where(function ($query) use ($now): void {
                $query->whereNull('ends_at')->orWhere('ends_at', '>=', $now);
            })
            ->orderByDesc('priority')
            ->orderByDesc('min_subtotal')
            ->get()
            ->first(function (ShippingSubsidyRule $rule) use ($province, $city, $district, $subDistrict, $weightGrams): bool {
                if ($rule->max_weight_grams !== null && $weightGrams > $rule->max_weight_grams) {
                    return false;
                }

                return match ($rule->scope_type) {
                    'all' => true,
                    'province' => $rule->scope_code === $province,
                    'city' => $rule->scope_code === $city,
                    'district' => $rule->scope_code === $district,
                    'sub_district' => $rule->scope_code === $subDistrict,
                    default => false,
                };
            });
    }
}
