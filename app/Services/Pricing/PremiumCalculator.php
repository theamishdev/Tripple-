<?php

namespace App\Services\Pricing;

use App\Exceptions\PricingValidationException;
use Carbon\Carbon;

class PremiumCalculator
{
    public const BASE_RATES = [
        'BASIC' => '12.00',
        'PLUS' => '20.00',
        'PREMIUM' => '30.00',
    ];

    public const DESTINATION_MULTIPLIERS = [
        'DOMESTIC' => '1.00',
        'INTERNATIONAL' => '1.80',
    ];

    public const COVERAGE_MULTIPLIERS = [
        '100000' => '1.00',
        '300000' => '1.25',
        '500000' => '1.50',
    ];

    /**
     * Calculate premium and return a snapshot array.
     *
     * @param string $planCode
     * @param float|int|string $sumInsured
     * @param string $destination
     * @param string $dateOfBirth
     * @param string $tripStart
     * @param string $tripEnd
     * @return array
     * @throws PricingValidationException
     */
    public function calculate(
        string $planCode,
        float|int|string $sumInsured,
        string $destination,
        string $dateOfBirth,
        string $tripStart,
        string $tripEnd
    ): array {
        $planCode = strtoupper(trim($planCode));
        if (!array_key_exists($planCode, self::BASE_RATES)) {
            throw new PricingValidationException("Unsupported plan code: [{$planCode}]. Supported plans are: BASIC, PLUS, PREMIUM.");
        }

        $destination = strtoupper(trim($destination));
        if (!array_key_exists($destination, self::DESTINATION_MULTIPLIERS)) {
            throw new PricingValidationException("Unsupported destination: [{$destination}]. Supported destinations are: DOMESTIC, INTERNATIONAL.");
        }

        $sumInsuredKey = (string) (int) $sumInsured;
        if (!array_key_exists($sumInsuredKey, self::COVERAGE_MULTIPLIERS)) {
            throw new PricingValidationException("Unsupported sum_insured: [{$sumInsured}]. Supported amounts are: 100000, 300000, 500000.");
        }

        $startDate = Carbon::parse($tripStart)->startOfDay();
        $endDate = Carbon::parse($tripEnd)->startOfDay();
        $dob = Carbon::parse($dateOfBirth)->startOfDay();

        if ($endDate->lt($startDate)) {
            throw new PricingValidationException("Trip end date ({$tripEnd}) cannot be before trip start date ({$tripStart}).");
        }

        if ($dob->gt($startDate)) {
            throw new PricingValidationException("Date of birth ({$dateOfBirth}) cannot be after trip start date ({$tripStart}).");
        }

        // Inclusive travel days: trip_end - trip_start + 1; minimum 1
        $travelDays = (int) $startDate->diffInDays($endDate) + 1;
        if ($travelDays < 1) {
            $travelDays = 1;
        }

        // Age calculated on trip start date
        $age = (int) $dob->diffInYears($startDate);
        $ageMultiplier = $this->resolveAgeMultiplier($age);

        $baseRate = self::BASE_RATES[$planCode];
        $destMultiplier = self::DESTINATION_MULTIPLIERS[$destination];
        $coverageMultiplier = self::COVERAGE_MULTIPLIERS[$sumInsuredKey];

        // Decimal-safe calculation using bcmath
        // Formula: premium = base_daily_rate * travel_days * age_multiplier * destination_multiplier * coverage_multiplier
        $step1 = bcmul($baseRate, (string) $travelDays, 4);
        $step2 = bcmul($step1, $ageMultiplier, 4);
        $step3 = bcmul($step2, $destMultiplier, 4);
        $rawPremium = bcmul($step3, $coverageMultiplier, 4);

        $roundedPremium = number_format((float) $rawPremium, 2, '.', '');

        return [
            'final_premium' => $roundedPremium,
            'currency' => 'INR',
            'travel_days' => $travelDays,
            'customer_age_at_trip_start' => $age,
            'base_daily_rate' => $baseRate,
            'age_multiplier' => $ageMultiplier,
            'destination_multiplier' => $destMultiplier,
            'coverage_multiplier' => $coverageMultiplier,
            'calculated_at' => Carbon::now()->toIso8601String(),
        ];
    }

    /**
     * Determine age multiplier based on age at trip start.
     * 0–17: 1.10; 18–60: 1.00; 61–70: 1.50; 71+: 2.00
     */
    public function resolveAgeMultiplier(int $age): string
    {
        if ($age < 0) {
            return '1.00';
        }

        if ($age <= 17) {
            return '1.10';
        }

        if ($age <= 60) {
            return '1.00';
        }

        if ($age <= 70) {
            return '1.50';
        }

        return '2.00';
    }
}
