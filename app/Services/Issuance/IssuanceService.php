<?php

namespace App\Services\Issuance;

use App\Enums\PolicyStatus;
use App\Exceptions\InsurerTemporaryFailureException;
use App\Exceptions\InsurerTimeoutException;
use App\Exceptions\InvalidStateTransitionException;
use App\Models\IssuanceAttempt;
use App\Models\PolicyRequest;
use App\Models\ProviderEvent;
use App\Services\MockInsurer\MockInsurerService;
use App\Services\StateMachine\PolicyStateMachine;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class IssuanceService
{
    public function __construct(
        protected MockInsurerService $mockInsurer,
        protected PolicyStateMachine $stateMachine
    ) {}

    /**
     * Submit policy to insurer safely with concurrency protection and state validation.
     *
     * @param PolicyRequest $policy
     * @param string|null $scenarioOverride
     * @return PolicyRequest
     */
    public function submit(PolicyRequest $policy, ?string $scenarioOverride = null): PolicyRequest
    {
        return DB::transaction(function () use ($policy, $scenarioOverride) {
            // Lock the record for update to prevent concurrent race conditions
            /** @var PolicyRequest $lockedPolicy */
            $lockedPolicy = PolicyRequest::where('id', $policy->id)->lockForUpdate()->firstOrFail();

            // Guard: If already issued, never re-submit or duplicate
            if ($lockedPolicy->status === PolicyStatus::ISSUED->value) {
                return $lockedPolicy;
            }

            // Guard: If in NEEDS_RECONCILIATION, must reconcile first, not blindly re-submit
            if ($lockedPolicy->status === PolicyStatus::NEEDS_RECONCILIATION->value) {
                throw new InvalidStateTransitionException(
                    "Policy [ID: {$lockedPolicy->id}] is in NEEDS_RECONCILIATION. You must reconcile status with the insurer before re-submitting."
                );
            }

            // Increment attempt count
            $attemptNumber = $lockedPolicy->attempt_count + 1;
            $lockedPolicy->attempt_count = $attemptNumber;
            $lockedPolicy->save();

            // Transition to SUBMITTED if not already there
            if ($lockedPolicy->status !== PolicyStatus::SUBMITTED->value) {
                $this->stateMachine->transition(
                    $lockedPolicy,
                    PolicyStatus::SUBMITTED,
                    "Submission attempt #{$attemptNumber} initiated",
                    ['attempt_number' => $attemptNumber, 'scenario' => $scenarioOverride]
                );
            }

            $scenario = $scenarioOverride ?: 'SUCCESS';
            $startTime = microtime(true);

            $requestPayload = [
                'policy_request_id' => $lockedPolicy->id,
                'uuid' => $lockedPolicy->uuid,
                'client_request_id' => $lockedPolicy->client_request_id,
                'agent_id' => $lockedPolicy->agent_id,
                'customer_name' => $lockedPolicy->customer_name,
                'date_of_birth' => $lockedPolicy->date_of_birth->format('Y-m-d'),
                'destination' => $lockedPolicy->destination,
                'trip_start' => $lockedPolicy->trip_start->format('Y-m-d'),
                'trip_end' => $lockedPolicy->trip_end->format('Y-m-d'),
                'travel_days' => $lockedPolicy->travel_days,
                'plan_code' => $lockedPolicy->plan_code,
                'sum_insured' => (float) $lockedPolicy->sum_insured,
                'premium_amount' => (float) $lockedPolicy->premium_amount,
            ];

            try {
                $response = $this->mockInsurer->issuePolicy($requestPayload, $scenario);
                $latencyMs = (int) round((microtime(true) - $startTime) * 1000);

                // Record attempt
                IssuanceAttempt::create([
                    'policy_request_id' => $lockedPolicy->id,
                    'attempt_number' => $attemptNumber,
                    'scenario' => $scenario,
                    'status' => $response['status'],
                    'request_payload' => $requestPayload,
                    'response_payload' => $response,
                    'latency_ms' => $latencyMs,
                    'provider_reference' => $response['provider_reference'] ?? null,
                ]);

                if ($response['status'] === 'SUCCESS') {
                    $lockedPolicy->provider_reference = $response['provider_reference'];
                    $lockedPolicy->policy_number = $response['policy_number'];
                    $lockedPolicy->issued_at = now();
                    $lockedPolicy->save();

                    $this->stateMachine->transition(
                        $lockedPolicy,
                        PolicyStatus::ISSUED,
                        "Insurer issued policy successfully with policy number {$response['policy_number']}",
                        ['provider_reference' => $response['provider_reference'], 'policy_number' => $response['policy_number']]
                    );
                } elseif ($response['status'] === 'PENDING') {
                    $lockedPolicy->provider_reference = $response['provider_reference'];
                    $lockedPolicy->save();

                    $this->stateMachine->transition(
                        $lockedPolicy,
                        PolicyStatus::PENDING_CONFIRMATION,
                        "Insurer acknowledged request as PENDING. Awaiting callback.",
                        ['provider_reference' => $response['provider_reference']]
                    );
                }

                return $lockedPolicy->fresh();
            } catch (InsurerTimeoutException $timeoutEx) {
                $latencyMs = (int) round((microtime(true) - $startTime) * 1000);

                if ($timeoutEx->providerReference) {
                    $lockedPolicy->provider_reference = $timeoutEx->providerReference;
                }
                $lockedPolicy->last_error = $timeoutEx->getMessage();
                $lockedPolicy->save();

                IssuanceAttempt::create([
                    'policy_request_id' => $lockedPolicy->id,
                    'attempt_number' => $attemptNumber,
                    'scenario' => $scenario,
                    'status' => 'TIMED_OUT',
                    'request_payload' => $requestPayload,
                    'response_payload' => null,
                    'error_message' => $timeoutEx->getMessage(),
                    'latency_ms' => $latencyMs,
                    'provider_reference' => $timeoutEx->providerReference,
                ]);

                // Ambiguous timeout: transition to NEEDS_RECONCILIATION rather than retrying blindly!
                $this->stateMachine->transition(
                    $lockedPolicy,
                    PolicyStatus::NEEDS_RECONCILIATION,
                    "Insurer call timed out. Policy may have been issued externally; reconciliation required before reissue.",
                    ['error' => $timeoutEx->getMessage(), 'provider_reference' => $timeoutEx->providerReference]
                );

                return $lockedPolicy->fresh();
            } catch (InsurerTemporaryFailureException $tempEx) {
                $latencyMs = (int) round((microtime(true) - $startTime) * 1000);

                $lockedPolicy->last_error = $tempEx->getMessage();
                $lockedPolicy->save();

                IssuanceAttempt::create([
                    'policy_request_id' => $lockedPolicy->id,
                    'attempt_number' => $attemptNumber,
                    'scenario' => $scenario,
                    'status' => 'TEMPORARY_FAILURE',
                    'request_payload' => $requestPayload,
                    'response_payload' => null,
                    'error_message' => $tempEx->getMessage(),
                    'latency_ms' => $latencyMs,
                    'provider_reference' => null,
                ]);

                $this->stateMachine->transition(
                    $lockedPolicy,
                    PolicyStatus::FAILED,
                    "Temporary provider failure (503): {$tempEx->getMessage()}. Safe for retry.",
                    ['error' => $tempEx->getMessage()]
                );

                return $lockedPolicy->fresh();
            }
        });
    }

    /**
     * Reconcile a policy in ambiguous or pending state with the insurer.
     *
     * @param PolicyRequest $policy
     * @return PolicyRequest
     */
    public function reconcile(PolicyRequest $policy): PolicyRequest
    {
        return DB::transaction(function () use ($policy) {
            /** @var PolicyRequest $lockedPolicy */
            $lockedPolicy = PolicyRequest::where('id', $policy->id)->lockForUpdate()->firstOrFail();

            if ($lockedPolicy->status === PolicyStatus::ISSUED->value) {
                return $lockedPolicy;
            }

            $remotePolicy = $this->mockInsurer->queryPolicyStatus(
                $lockedPolicy->provider_reference,
                $lockedPolicy->agent_id,
                $lockedPolicy->client_request_id
            );

            if ($remotePolicy && $remotePolicy->status === 'ISSUED') {
                $lockedPolicy->provider_reference = $remotePolicy->provider_reference;
                $lockedPolicy->policy_number = $remotePolicy->policy_number;
                $lockedPolicy->issued_at = now();
                $lockedPolicy->save();

                $this->stateMachine->transition(
                    $lockedPolicy,
                    PolicyStatus::ISSUED,
                    "Reconciliation succeeded: remote policy found with number {$remotePolicy->policy_number}",
                    ['provider_reference' => $remotePolicy->provider_reference, 'policy_number' => $remotePolicy->policy_number]
                );
            } elseif (!$remotePolicy) {
                // Insurer has no record: safe to mark FAILED so agent can retry
                $this->stateMachine->transition(
                    $lockedPolicy,
                    PolicyStatus::FAILED,
                    "Reconciliation completed: no remote policy found on insurer side. Safe to retry.",
                    ['checked_at' => now()->toIso8601String()]
                );
            }

            return $lockedPolicy->fresh();
        });
    }

    /**
     * Process an incoming callback from insurer with idempotency.
     *
     * @param array $data
     * @return array
     */
    public function handleCallback(array $data): array
    {
        $eventId = $data['event_id'] ?? null;
        if (empty($eventId)) {
            return [
                'success' => false,
                'status' => 'REJECTED',
                'message' => 'Missing required field: event_id',
            ];
        }

        return DB::transaction(function () use ($data, $eventId) {
            // Check if this event was already received (idempotency check)
            $existingEvent = ProviderEvent::where('event_id', $eventId)->first();
            if ($existingEvent) {
                return [
                    'success' => true,
                    'status' => 'DUPLICATE_IGNORED',
                    'message' => "Event {$eventId} has already been processed idempotently.",
                    'event_id' => $eventId,
                ];
            }

            $providerRef = $data['provider_reference'] ?? null;
            $eventType = $data['event_type'] ?? 'POLICY_ISSUED';
            $policyNumber = $data['policy_number'] ?? null;

            // Locate the corresponding PolicyRequest
            $policy = null;
            if ($providerRef) {
                $policy = PolicyRequest::where('provider_reference', $providerRef)->first();
            }
            if (!$policy && isset($data['client_request_id'], $data['agent_id'])) {
                $policy = PolicyRequest::where('client_request_id', $data['client_request_id'])
                    ->where('agent_id', $data['agent_id'])
                    ->first();
            }

            // Record the incoming provider event
            $eventRecord = ProviderEvent::create([
                'event_id' => $eventId,
                'provider_reference' => $providerRef,
                'policy_request_id' => $policy?->id,
                'event_type' => $eventType,
                'payload' => $data,
                'status' => 'PROCESSED',
                'processed_at' => now(),
            ]);

            // If no matching policy, store the event and acknowledge
            if (!$policy) {
                return [
                    'success' => true,
                    'status' => 'UNMATCHED_LOGGED',
                    'message' => 'Event recorded but no matching policy found in system.',
                    'event_id' => $eventId,
                ];
            }

            // Never mutate an already ISSUED policy with regression
            if ($policy->status === PolicyStatus::ISSUED->value) {
                return [
                    'success' => true,
                    'status' => 'ALREADY_ISSUED',
                    'message' => "Policy {$policy->id} is already in confirmed ISSUED state.",
                    'policy_number' => $policy->policy_number,
                    'event_id' => $eventId,
                ];
            }

            if ($eventType === 'POLICY_ISSUED') {
                $policyNumber = $policyNumber ?: ('POL-' . date('Ymd') . '-' . strtoupper(\Illuminate\Support\Str::random(6)));
                $policy->policy_number = $policyNumber;
                $policy->issued_at = now();
                $policy->save();

                $this->stateMachine->transition(
                    $policy,
                    PolicyStatus::ISSUED,
                    "Confirmed via provider callback event [{$eventId}] with policy number {$policyNumber}",
                    ['event_id' => $eventId, 'provider_reference' => $providerRef, 'policy_number' => $policyNumber]
                );
            } elseif ($eventType === 'POLICY_FAILED') {
                $this->stateMachine->transition(
                    $policy,
                    PolicyStatus::FAILED,
                    "Provider callback reported policy issuance failure: " . ($data['reason'] ?? 'Unknown error'),
                    ['event_id' => $eventId]
                );
            }

            return [
                'success' => true,
                'status' => 'PROCESSED',
                'message' => 'Callback processed and policy state transitioned.',
                'event_id' => $eventId,
                'policy_id' => $policy->id,
                'policy_status' => $policy->fresh()->status,
            ];
        });
    }
}
