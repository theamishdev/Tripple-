<?php

namespace Tests\Feature;

use App\Models\PolicyRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PolicyIntakeApiTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Requirement: Valid request produces expected premium and status (201 Created).
     * Matching the exact example from section 4 of the assessment document:
     * - PLUS, sum_insured 300000, INTERNATIONAL, DOB 1998-04-10, 2026-11-10 to 2026-11-14 (5 days)
     * - Expected premium: 20 * 5 * 1.00 * 1.80 * 1.25 = ₹225.00
     */
    public function test_valid_request_creates_policy_with_expected_premium_and_status(): void
    {
        $payload = [
            'client_request_id' => 'AGT-2026-0001',
            'agent_id' => 'AGENT-17',
            'customer_name' => 'Sample Customer',
            'date_of_birth' => '1998-04-10',
            'destination' => 'INTERNATIONAL',
            'trip_start' => '2026-11-10',
            'trip_end' => '2026-11-14',
            'plan_code' => 'PLUS',
            'sum_insured' => 300000,
        ];

        $response = $this->postJson('/api/policy-requests', $payload);

        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
                'is_duplicate' => false,
                'data' => [
                    'client_request_id' => 'AGT-2026-0001',
                    'agent_id' => 'AGENT-17',
                    'customer_name' => 'Sample Customer',
                    'status' => 'VALIDATED',
                    'travel_days' => 5,
                    'premium_amount' => '225.00',
                    'currency' => 'INR',
                ],
            ]);

        $this->assertDatabaseHas('policy_requests', [
            'client_request_id' => 'AGT-2026-0001',
            'agent_id' => 'AGENT-17',
            'premium_amount' => 225.00,
            'status' => 'VALIDATED',
        ]);
    }

    /**
     * Requirement: Invalid travel dates (trip_end < trip_start) are rejected with HTTP 422.
     */
    public function test_invalid_travel_dates_rejected_with_422(): void
    {
        $payload = [
            'client_request_id' => 'AGT-2026-0002',
            'agent_id' => 'AGENT-17',
            'customer_name' => 'John Traveler',
            'date_of_birth' => '1995-05-15',
            'destination' => 'DOMESTIC',
            'trip_start' => '2026-11-15',
            'trip_end' => '2026-11-10', // Invalid: before start
            'plan_code' => 'BASIC',
            'sum_insured' => 100000,
        ];

        $response = $this->postJson('/api/policy-requests', $payload);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['trip_end']);
    }

    /**
     * Requirement: Unsupported plan is rejected with HTTP 422.
     */
    public function test_unsupported_plan_rejected_with_422(): void
    {
        $payload = [
            'client_request_id' => 'AGT-2026-0003',
            'agent_id' => 'AGENT-17',
            'customer_name' => 'Jane Traveler',
            'date_of_birth' => '1995-05-15',
            'destination' => 'DOMESTIC',
            'trip_start' => '2026-11-10',
            'trip_end' => '2026-11-15',
            'plan_code' => 'SUPER_PLATINUM', // Unsupported plan
            'sum_insured' => 100000,
        ];

        $response = $this->postJson('/api/policy-requests', $payload);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['plan_code']);
    }

    /**
     * Requirement: Unsupported sum_insured is rejected with HTTP 422.
     */
    public function test_unsupported_coverage_rejected_with_422(): void
    {
        $payload = [
            'client_request_id' => 'AGT-2026-0004',
            'agent_id' => 'AGENT-17',
            'customer_name' => 'Jane Traveler',
            'date_of_birth' => '1995-05-15',
            'destination' => 'DOMESTIC',
            'trip_start' => '2026-11-10',
            'trip_end' => '2026-11-15',
            'plan_code' => 'BASIC',
            'sum_insured' => 250000, // Unsupported sum_insured
        ];

        $response = $this->postJson('/api/policy-requests', $payload);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['sum_insured']);
    }

    /**
     * Requirement: Repeated agent_id + client_request_id does not create another logical request.
     * Near-simultaneous or repeated POST returns 200 with the existing record and is_duplicate = true.
     */
    public function test_repeated_submission_is_idempotent_and_returns_200(): void
    {
        $payload = [
            'client_request_id' => 'AGT-IDEMPOTENT-001',
            'agent_id' => 'AGENT-99',
            'customer_name' => 'Repeat Customer',
            'date_of_birth' => '1985-02-20',
            'destination' => 'DOMESTIC',
            'trip_start' => '2026-12-01',
            'trip_end' => '2026-12-05',
            'plan_code' => 'BASIC',
            'sum_insured' => 100000,
        ];

        // First submission -> 201 Created
        $first = $this->postJson('/api/policy-requests', $payload);
        $first->assertStatus(201);
        $policyId = $first->json('data.id');

        // Repeated submission with same agent_id and client_request_id -> 200 OK
        $second = $this->postJson('/api/policy-requests', $payload);
        $second->assertStatus(200)
            ->assertJson([
                'success' => true,
                'is_duplicate' => true,
                'data' => [
                    'id' => $policyId,
                    'client_request_id' => 'AGT-IDEMPOTENT-001',
                ],
            ]);

        // Assert database still only contains exactly 1 record
        $this->assertEquals(1, PolicyRequest::where('client_request_id', 'AGT-IDEMPOTENT-001')->count());
    }

    /**
     * Requirement: List policy requests with pagination and status filter.
     */
    public function test_can_list_and_filter_policy_requests(): void
    {
        // Create 2 records
        $this->postJson('/api/policy-requests', [
            'client_request_id' => 'REQ-LIST-01',
            'agent_id' => 'AGENT-01',
            'customer_name' => 'Customer 1',
            'date_of_birth' => '1990-01-01',
            'destination' => 'DOMESTIC',
            'trip_start' => '2026-10-01',
            'trip_end' => '2026-10-05',
            'plan_code' => 'BASIC',
            'sum_insured' => 100000,
        ]);

        $this->postJson('/api/policy-requests', [
            'client_request_id' => 'REQ-LIST-02',
            'agent_id' => 'AGENT-02',
            'customer_name' => 'Customer 2',
            'date_of_birth' => '1992-01-01',
            'destination' => 'INTERNATIONAL',
            'trip_start' => '2026-10-01',
            'trip_end' => '2026-10-05',
            'plan_code' => 'PLUS',
            'sum_insured' => 300000,
        ]);

        $response = $this->getJson('/api/policy-requests?agent_id=AGENT-01');
        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
        $this->assertEquals('REQ-LIST-01', $response->json('data.0.client_request_id'));
    }
}
