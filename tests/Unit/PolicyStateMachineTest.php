<?php

namespace Tests\Unit;

use App\Enums\PolicyStatus;
use App\Exceptions\InvalidStateTransitionException;
use App\Models\PolicyRequest;
use App\Services\StateMachine\PolicyStateMachine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PolicyStateMachineTest extends TestCase
{
    use RefreshDatabase;

    private PolicyStateMachine $stateMachine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->stateMachine = new PolicyStateMachine();
    }

    private function createSamplePolicy(string $status = 'RECEIVED'): PolicyRequest
    {
        return PolicyRequest::create([
            'client_request_id' => 'REQ-' . uniqid(),
            'agent_id' => 'AGENT-01',
            'customer_name' => 'John Doe',
            'date_of_birth' => '1990-01-01',
            'destination' => 'DOMESTIC',
            'trip_start' => '2026-06-01',
            'trip_end' => '2026-06-05',
            'travel_days' => 5,
            'plan_code' => 'BASIC',
            'sum_insured' => 100000,
            'premium_amount' => 60.00,
            'currency' => 'INR',
            'pricing_breakdown' => ['sample' => true],
            'status' => $status,
        ]);
    }

    public function test_can_transition_through_happy_path_and_records_history(): void
    {
        $policy = $this->createSamplePolicy('RECEIVED');

        // RECEIVED -> VALIDATED
        $this->stateMachine->transition($policy, PolicyStatus::VALIDATED, 'Input validated and priced');
        $this->assertEquals(PolicyStatus::VALIDATED->value, $policy->fresh()->status);

        // VALIDATED -> SUBMITTED
        $this->stateMachine->transition($policy, PolicyStatus::SUBMITTED, 'Submitted to insurer');
        $this->assertEquals(PolicyStatus::SUBMITTED->value, $policy->fresh()->status);

        // SUBMITTED -> ISSUED
        $this->stateMachine->transition($policy, PolicyStatus::ISSUED, 'Policy issued successfully');
        $this->assertEquals(PolicyStatus::ISSUED->value, $policy->fresh()->status);

        // Check history records
        $history = $policy->stateHistories;
        $this->assertCount(3, $history);
        $this->assertEquals('RECEIVED', $history[0]->from_state);
        $this->assertEquals('VALIDATED', $history[0]->to_state);
        $this->assertEquals('VALIDATED', $history[1]->from_state);
        $this->assertEquals('SUBMITTED', $history[1]->to_state);
        $this->assertEquals('SUBMITTED', $history[2]->from_state);
        $this->assertEquals('ISSUED', $history[2]->to_state);
    }

    public function test_cannot_regress_from_issued_to_submitted(): void
    {
        $policy = $this->createSamplePolicy('ISSUED');

        $this->expectException(InvalidStateTransitionException::class);
        $this->stateMachine->transition($policy, PolicyStatus::SUBMITTED, 'Attempting illegal regression');
    }

    public function test_cannot_regress_from_issued_to_failed(): void
    {
        $policy = $this->createSamplePolicy('ISSUED');

        $this->expectException(InvalidStateTransitionException::class);
        $this->stateMachine->transition($policy, PolicyStatus::FAILED, 'Attempting illegal failure on issued policy');
    }

    public function test_cannot_skip_from_received_directly_to_issued(): void
    {
        $policy = $this->createSamplePolicy('RECEIVED');

        $this->expectException(InvalidStateTransitionException::class);
        $this->stateMachine->transition($policy, PolicyStatus::ISSUED, 'Cannot skip directly');
    }
}
