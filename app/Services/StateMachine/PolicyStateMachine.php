<?php

namespace App\Services\StateMachine;

use App\Enums\PolicyStatus;
use App\Exceptions\InvalidStateTransitionException;
use App\Models\PolicyRequest;
use App\Models\PolicyStateHistory;
use Illuminate\Support\Facades\DB;

class PolicyStateMachine
{
    /**
     * Allowed transition matrix.
     * key = from_state, value = list of allowed to_states
     */
    protected const ALLOWED_TRANSITIONS = [
        PolicyStatus::RECEIVED->value => [
            PolicyStatus::VALIDATED->value,
            PolicyStatus::FAILED->value,
        ],
        PolicyStatus::VALIDATED->value => [
            PolicyStatus::SUBMITTED->value,
            PolicyStatus::FAILED->value,
        ],
        PolicyStatus::SUBMITTED->value => [
            PolicyStatus::ISSUED->value,
            PolicyStatus::PENDING_CONFIRMATION->value,
            PolicyStatus::NEEDS_RECONCILIATION->value,
            PolicyStatus::FAILED->value,
        ],
        PolicyStatus::PENDING_CONFIRMATION->value => [
            PolicyStatus::ISSUED->value,
            PolicyStatus::FAILED->value,
            PolicyStatus::NEEDS_RECONCILIATION->value,
        ],
        PolicyStatus::NEEDS_RECONCILIATION->value => [
            PolicyStatus::ISSUED->value,
            PolicyStatus::FAILED->value,
            PolicyStatus::SUBMITTED->value,
        ],
        PolicyStatus::FAILED->value => [
            PolicyStatus::SUBMITTED->value,
            PolicyStatus::VALIDATED->value,
        ],
        // ISSUED is strictly terminal!
        PolicyStatus::ISSUED->value => [],
    ];

    /**
     * Transition a policy request to a new state with audit history.
     *
     * @param PolicyRequest $policy
     * @param PolicyStatus|string $targetState
     * @param string $reason
     * @param array $metadata
     * @return PolicyRequest
     * @throws InvalidStateTransitionException
     */
    public function transition(
        PolicyRequest $policy,
        PolicyStatus|string $targetState,
        string $reason,
        array $metadata = []
    ): PolicyRequest {
        $toState = $targetState instanceof PolicyStatus ? $targetState->value : $targetState;
        $fromState = $policy->status;

        if ($fromState === $toState) {
            // Already in target state; no-op or log idempotent touch
            return $policy;
        }

        if (!$this->canTransition($fromState, $toState)) {
            throw new InvalidStateTransitionException(
                "Illegal state transition requested for Policy [ID: {$policy->id}]: from '{$fromState}' to '{$toState}'. ISSUED policies are immutable and illegal regressions are forbidden."
            );
        }

        DB::transaction(function () use ($policy, $fromState, $toState, $reason, $metadata) {
            $policy->status = $toState;
            $policy->save();

            PolicyStateHistory::create([
                'policy_request_id' => $policy->id,
                'from_state' => $fromState,
                'to_state' => $toState,
                'reason' => $reason,
                'metadata' => $metadata,
                'created_at' => now(),
            ]);
        });

        return $policy->fresh();
    }

    /**
     * Check if a transition from -> to is valid.
     */
    public function canTransition(?string $fromState, string $toState): bool
    {
        if ($fromState === null) {
            return $toState === PolicyStatus::RECEIVED->value;
        }

        $allowed = self::ALLOWED_TRANSITIONS[$fromState] ?? [];
        return in_array($toState, $allowed, true);
    }
}
