<?php

namespace Tests\Unit;

use App\Exceptions\PricingValidationException;
use App\Services\Pricing\PremiumCalculator;
use PHPUnit\Framework\TestCase;

class PremiumCalculatorTest extends TestCase
{
    private PremiumCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calculator = new PremiumCalculator();
    }

    /**
     * Test the example from Section 4.1 in the assessment document:
     * - Travel days: 2026-11-10 to 2026-11-14 = 5 days
     * - Age: DOB 1998-04-10 on 2026-11-10 = 28 years (multiplier 1.00)
     * - Plan: PLUS (20.00)
     * - Destination: INTERNATIONAL (1.80)
     * - Coverage: 300,000 (1.25)
     * Formula: 20 * 5 * 1.00 * 1.80 * 1.25 = ₹225.00
     */
    public function test_document_example_premium_matches_expected_225(): void
    {
        $breakdown = $this->calculator->calculate(
            planCode: 'PLUS',
            sumInsured: 300000,
            destination: 'INTERNATIONAL',
            dateOfBirth: '1998-04-10',
            tripStart: '2026-11-10',
            tripEnd: '2026-11-14'
        );

        $this->assertEquals('225.00', $breakdown['final_premium']);
        $this->assertEquals(5, $breakdown['travel_days']);
        $this->assertEquals('1.00', $breakdown['age_multiplier']);
        $this->assertEquals('1.80', $breakdown['destination_multiplier']);
        $this->assertEquals('1.25', $breakdown['coverage_multiplier']);
        $this->assertEquals('20.00', $breakdown['base_daily_rate']);
    }

    /**
     * Test all age tiers:
     * 0–17: 1.10; 18–60: 1.00; 61–70: 1.50; 71+: 2.00
     */
    public function test_age_multipliers(): void
    {
        $this->assertEquals('1.10', $this->calculator->resolveAgeMultiplier(16));
        $this->assertEquals('1.10', $this->calculator->resolveAgeMultiplier(17));
        $this->assertEquals('1.00', $this->calculator->resolveAgeMultiplier(18));
        $this->assertEquals('1.00', $this->calculator->resolveAgeMultiplier(60));
        $this->assertEquals('1.50', $this->calculator->resolveAgeMultiplier(61));
        $this->assertEquals('1.50', $this->calculator->resolveAgeMultiplier(70));
        $this->assertEquals('2.00', $this->calculator->resolveAgeMultiplier(71));
        $this->assertEquals('2.00', $this->calculator->resolveAgeMultiplier(85));
    }

    /**
     * Test destination multipliers:
     * DOMESTIC = 1.00, INTERNATIONAL = 1.80
     */
    public function test_domestic_vs_international_destination_multiplier(): void
    {
        $domestic = $this->calculator->calculate(
            planCode: 'BASIC', // 12.00
            sumInsured: 100000, // 1.00
            destination: 'DOMESTIC', // 1.00
            dateOfBirth: '2000-01-01', // age > 18 -> 1.00
            tripStart: '2026-05-01',
            tripEnd: '2026-05-01' // 1 day
        );
        // 12 * 1 * 1.00 * 1.00 * 1.00 = 12.00
        $this->assertEquals('12.00', $domestic['final_premium']);

        $intl = $this->calculator->calculate(
            planCode: 'BASIC',
            sumInsured: 100000,
            destination: 'INTERNATIONAL',
            dateOfBirth: '2000-01-01',
            tripStart: '2026-05-01',
            tripEnd: '2026-05-01'
        );
        // 12 * 1 * 1.00 * 1.80 * 1.00 = 21.60
        $this->assertEquals('21.60', $intl['final_premium']);
    }

    /**
     * Test plan rates:
     * BASIC = 12, PLUS = 20, PREMIUM = 30
     */
    public function test_plan_base_rates(): void
    {
        $basic = $this->calculator->calculate('BASIC', 100000, 'DOMESTIC', '2000-01-01', '2026-05-01', '2026-05-01');
        $this->assertEquals('12.00', $basic['final_premium']);

        $plus = $this->calculator->calculate('PLUS', 100000, 'DOMESTIC', '2000-01-01', '2026-05-01', '2026-05-01');
        $this->assertEquals('20.00', $plus['final_premium']);

        $premium = $this->calculator->calculate('PREMIUM', 100000, 'DOMESTIC', '2000-01-01', '2026-05-01', '2026-05-01');
        $this->assertEquals('30.00', $premium['final_premium']);
    }

    /**
     * Test coverage multipliers:
     * 100k = 1.00, 300k = 1.25, 500k = 1.50
     */
    public function test_coverage_multipliers(): void
    {
        $c1 = $this->calculator->calculate('BASIC', 100000, 'DOMESTIC', '2000-01-01', '2026-05-01', '2026-05-01');
        $this->assertEquals('12.00', $c1['final_premium']);

        $c2 = $this->calculator->calculate('BASIC', 300000, 'DOMESTIC', '2000-01-01', '2026-05-01', '2026-05-01');
        $this->assertEquals('15.00', $c2['final_premium']); // 12 * 1.25 = 15.00

        $c3 = $this->calculator->calculate('BASIC', 500000, 'DOMESTIC', '2000-01-01', '2026-05-01', '2026-05-01');
        $this->assertEquals('18.00', $c3['final_premium']); // 12 * 1.50 = 18.00
    }

    /**
     * Test rejection of unsupported plan.
     */
    public function test_rejects_unsupported_plan(): void
    {
        $this->expectException(PricingValidationException::class);
        $this->expectExceptionMessage('Unsupported plan code');
        $this->calculator->calculate('GOLD_TIER', 100000, 'DOMESTIC', '2000-01-01', '2026-05-01', '2026-05-05');
    }

    /**
     * Test rejection of unsupported coverage.
     */
    public function test_rejects_unsupported_coverage(): void
    {
        $this->expectException(PricingValidationException::class);
        $this->expectExceptionMessage('Unsupported sum_insured');
        $this->calculator->calculate('BASIC', 200000, 'DOMESTIC', '2000-01-01', '2026-05-01', '2026-05-05');
    }

    /**
     * Test rejection of invalid date logic (trip_end < trip_start).
     */
    public function test_rejects_invalid_date_range(): void
    {
        $this->expectException(PricingValidationException::class);
        $this->expectExceptionMessage('cannot be before trip start date');
        $this->calculator->calculate('BASIC', 100000, 'DOMESTIC', '2000-01-01', '2026-05-10', '2026-05-05');
    }
}
