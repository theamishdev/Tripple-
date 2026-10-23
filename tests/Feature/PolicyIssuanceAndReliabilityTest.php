<?php

namespace Tests\Feature;

use App\Models\PolicyRequest;
use App\Models\ProviderEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PolicyIssuanceAndReliabilityTest extends TestCase
{
    use RefreshDatabase;

    private function createValidatedPolicy(string $clientRequestId = 'AGT-TEST-001', string $agentId = 'AGENT-01'): PolicyRequest
    {
        $response = $this->postJson('/api/policy-requests', [
            'client_request_id' => $clientRequestId,
            'agent_id' => $agentId,
            'customer_name' => 'Alice Walker',
            'date_of_birth' => '1992-03-12',
            'destination' => 'DOMESTIC',
            'trip_start' => '2026-12-10',
            'trip_end' => '2026-12-14',
            'plan_code' => 'PLUS',
            'sum_insured' => 300000,
        ]);

        return PolicyRequest::findOrFail($response->json('data.id'));
    }

    /**
     * Requirement: Successful provider response produces exactly one unique policy number.
     */
    public function test_successful_provider_response_produces_one_policy_number(): void
    {
        $policy = $this->createValidatedPolicy('REQ-SUCCESS-01');

        $response = $this->postJson("/api/policy-requests/{$policy->id}/submit", [
            'mock_scenario' => 'SUCCESS',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'id' => $policy->id,
                    'status' => 'ISSUED',
                ],
            ]);

        $policyFresh = $policy->fresh();
        $this->assertNotNull($policyFresh->policy_number);
        $this->assertStringStartsWith('POL-', $policyFresh->policy_number);
        $this->assertEquals(1, $policyFresh->attempts()->count());
        $this->assertEquals('SUCCESS', $policyFresh->attempts->first()->status);

        // Safe repeated submission does not create another policy number or call insurer again
        $repeatResponse = $this->postJson("/api/policy-requests/{$policy->id}/submit");
        $repeatResponse->assertStatus(200);
        $this->assertEquals($policyFresh->policy_number, $policyFresh->fresh()->policy_number);
        $this->assertEquals(1, $policyFresh->fresh()->attempts()->count());
    }

    /**
     * Requirement (Reliability Case 1): Duplicate Callback Idempotency.
     * The same provider event is delivered multiple times; processing must be idempotent
     * and cannot corrupt or duplicate state.
     */
    public function test_duplicate_callback_is_idempotent_and_does_not_corrupt_state(): void
    {
        $policy = $this->createValidatedPolicy('REQ-CALLBACK-01');

        // Submit with DELAYED_CALLBACK scenario -> enters PENDING_CONFIRMATION
        $submit = $this->postJson("/api/policy-requests/{$policy->id}/submit", [
            'mock_scenario' => 'DELAYED_CALLBACK',
        ]);
        $submit->assertStatus(200)
            ->assertJson(['data' => ['status' => 'PENDING_CONFIRMATION']]);

        $providerRef = $policy->fresh()->provider_reference;
        $eventId = 'EVT-IDEMP-9999';

        $callbackPayload = [
            'event_id' => $eventId,
            'event_type' => 'POLICY_ISSUED',
            'provider_reference' => $providerRef,
            'policy_number' => 'POL-CONFIRMED-12345',
        ];

        // 1st delivery of webhook callback -> transitions to ISSUED
        $firstCall = $this->postJson('/api/mock-insurer/callback', $callbackPayload);
        $firstCall->assertStatus(200)
            ->assertJson([
                'status' => 'PROCESSED',
                'policy_status' => 'ISSUED',
            ]);

        $this->assertEquals('ISSUED', $policy->fresh()->status);
        $this->assertEquals('POL-CONFIRMED-12345', $policy->fresh()->policy_number);

        // 2nd delivery of the exact same callback event -> returns DUPLICATE_IGNORED
        $secondCall = $this->postJson('/api/mock-insurer/callback', $callbackPayload);
        $secondCall->assertStatus(200)
            ->assertJson([
                'status' => 'DUPLICATE_IGNORED',
            ]);

        // 3rd delivery -> returns DUPLICATE_IGNORED
        $thirdCall = $this->postJson('/api/mock-insurer/callback', $callbackPayload);
        $thirdCall->assertStatus(200)
            ->assertJson([
                'status' => 'DUPLICATE_IGNORED',
            ]);

        // Assert only 1 event recorded in provider_events table
        $this->assertEquals(1, ProviderEvent::where('event_id', $eventId)->count());
        $this->assertEquals('ISSUED', $policy->fresh()->status);
    }

    /**
     * Requirement (Reliability Case 2): Timeout After Success & Reconciliation.
     * The insurer may have issued a policy even when the client receives no response.
     * System marks as NEEDS_RECONCILIATION, and does not blindly issue again.
     * Reconcile endpoint verifies provider state and completes issuance without duplicate policy.
     */
    public function test_timeout_after_issue_transitions_to_reconciliation_and_reconciles_safely(): void
    {
        $policy = $this->createValidatedPolicy('REQ-TIMEOUT-01');

        // Submit with TIMEOUT_AFTER_ISSUE scenario
        $submit = $this->postJson("/api/policy-requests/{$policy->id}/submit", [
            'mock_scenario' => 'TIMEOUT_AFTER_ISSUE',
        ]);

        $submit->assertStatus(200)
            ->assertJson([
                'data' => [
                    'status' => 'NEEDS_RECONCILIATION',
                ],
            ]);

        $fresh = $policy->fresh();
        $this->assertEquals('NEEDS_RECONCILIATION', $fresh->status);
        $this->assertNotNull($fresh->provider_reference);

        // Attempting to blindly re-submit without reconciliation is rejected
        $blindReissue = $this->postJson("/api/policy-requests/{$policy->id}/submit");
        $blindReissue->assertStatus(422)
            ->assertJson([
                'success' => false,
            ]);

        // Trigger safe reconciliation
        $reconcile = $this->postJson("/api/policy-requests/{$policy->id}/reconcile");
        $reconcile->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'status' => 'ISSUED',
                ],
            ]);

        $reconciledPolicy = $policy->fresh();
        $this->assertEquals('ISSUED', $reconciledPolicy->status);
        $this->assertNotNull($reconciledPolicy->policy_number);
    }

    /**
     * Requirement (Reliability Case 3): Retry after temporary failure.
     * External insurer returns 503; system marks failure; retry succeeds and preserves original premium snapshot.
     */
    public function test_temporary_failure_and_safe_retry_preserves_original_premium(): void
    {
        $policy = $this->createValidatedPolicy('REQ-RETRY-01');
        $originalPremium = (float) $policy->premium_amount;

        // Attempt 1: TEMPORARY_FAILURE
        $attempt1 = $this->postJson("/api/policy-requests/{$policy->id}/submit", [
            'mock_scenario' => 'TEMPORARY_FAILURE',
        ]);

        $attempt1->assertStatus(200)
            ->assertJson(['data' => ['status' => 'FAILED']]);

        $this->assertEquals(1, $policy->fresh()->attempt_count);
        $this->assertEquals('FAILED', $policy->fresh()->status);
        $this->assertEquals($originalPremium, (float) $policy->fresh()->premium_amount);

        // Attempt 2: Retry with SUCCESS scenario
        $attempt2 = $this->postJson("/api/policy-requests/{$policy->id}/submit", [
            'mock_scenario' => 'SUCCESS',
        ]);

        $attempt2->assertStatus(200)
            ->assertJson(['data' => ['status' => 'ISSUED']]);

        $policyFresh = $policy->fresh();
        $this->assertEquals('ISSUED', $policyFresh->status);
        $this->assertEquals(2, $policyFresh->attempt_count);
        // Premium was NOT altered or recalculated
        $this->assertEquals($originalPremium, (float) $policyFresh->premium_amount);
    }

    /**
     * Requirement: A repeated callback cannot overwrite a confirmed ISSUED state.
     */
    public function test_repeated_failure_callback_cannot_overwrite_confirmed_issued_state(): void
    {
        $policy = $this->createValidatedPolicy('REQ-IMMUTABLE-01');

        // Transition to ISSUED
        $this->postJson("/api/policy-requests/{$policy->id}/submit", [
            'mock_scenario' => 'SUCCESS',
        ]);
        $this->assertEquals('ISSUED', $policy->fresh()->status);

        // Now send an out-of-order or duplicate failure callback with different event_id
        $response = $this->postJson('/api/mock-insurer/callback', [
            'event_id' => 'EVT-LATE-FAIL-01',
            'event_type' => 'POLICY_FAILED',
            'provider_reference' => $policy->fresh()->provider_reference,
            'reason' => 'Late underwriting rejection',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'ALREADY_ISSUED',
            ]);

        // State must remain ISSUED
        $this->assertEquals('ISSUED', $policy->fresh()->status);
    }
}
