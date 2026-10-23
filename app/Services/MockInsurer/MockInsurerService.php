<?php

namespace App\Services\MockInsurer;

use App\Exceptions\InsurerTemporaryFailureException;
use App\Exceptions\InsurerTimeoutException;
use App\Models\MockInsurerPolicy;
use Illuminate\Support\Str;

class MockInsurerService
{
    /**
     * Submit an issuance request to the simulated external insurer.
     *
     * @param array $payload
     * @param string $scenario
     * @return array
     * @throws InsurerTemporaryFailureException
     * @throws InsurerTimeoutException
     */
    public function issuePolicy(array $payload, string $scenario = 'SUCCESS'): array
    {
        $scenario = strtoupper(trim($scenario));
        $clientRequestId = $payload['client_request_id'] ?? 'UNKNOWN';
        $agentId = $payload['agent_id'] ?? 'UNKNOWN';
        $providerReference = 'MOCK-REF-' . strtoupper(Str::random(10));
        $policyNumber = 'POL-' . date('Ymd') . '-' . strtoupper(Str::random(6));

        switch ($scenario) {
            case 'TEMPORARY_FAILURE':
                // Simulate network blip or 503 from external insurer
                throw new InsurerTemporaryFailureException("Insurer service temporarily unavailable (503). Retries allowed.");

            case 'TIMEOUT_AFTER_ISSUE':
                // Creates policy internally in insurer database, but caller connection times out!
                MockInsurerPolicy::create([
                    'provider_reference' => $providerReference,
                    'client_request_id' => $clientRequestId,
                    'agent_id' => $agentId,
                    'policy_number' => $policyNumber,
                    'status' => 'ISSUED',
                    'plan_code' => $payload['plan_code'] ?? 'BASIC',
                    'premium_amount' => $payload['premium_amount'] ?? 0,
                    'payload' => $payload,
                ]);

                throw new InsurerTimeoutException(
                    "Simulated network timeout after insurer issued policy. Client received no response.",
                    $providerReference
                );

            case 'DELAYED_CALLBACK':
                // Asynchronous insurer: acknowledges intake as PENDING; will send callback later
                MockInsurerPolicy::create([
                    'provider_reference' => $providerReference,
                    'client_request_id' => $clientRequestId,
                    'agent_id' => $agentId,
                    'policy_number' => $policyNumber,
                    'status' => 'PENDING',
                    'plan_code' => $payload['plan_code'] ?? 'BASIC',
                    'premium_amount' => $payload['premium_amount'] ?? 0,
                    'payload' => $payload,
                ]);

                return [
                    'status' => 'PENDING',
                    'provider_reference' => $providerReference,
                    'message' => 'Policy intake accepted; awaiting underwriting confirmation via callback.',
                ];

            case 'SUCCESS':
            default:
                // Normal synchronous success
                MockInsurerPolicy::create([
                    'provider_reference' => $providerReference,
                    'client_request_id' => $clientRequestId,
                    'agent_id' => $agentId,
                    'policy_number' => $policyNumber,
                    'status' => 'ISSUED',
                    'plan_code' => $payload['plan_code'] ?? 'BASIC',
                    'premium_amount' => $payload['premium_amount'] ?? 0,
                    'payload' => $payload,
                ]);

                return [
                    'status' => 'SUCCESS',
                    'provider_reference' => $providerReference,
                    'policy_number' => $policyNumber,
                    'message' => 'Policy successfully issued by insurer.',
                ];
        }
    }

    /**
     * Query insurer for policy status (Reconciliation lookup).
     */
    public function queryPolicyStatus(?string $providerReference, ?string $agentId = null, ?string $clientRequestId = null): ?MockInsurerPolicy
    {
        if ($providerReference) {
            $record = MockInsurerPolicy::where('provider_reference', $providerReference)->first();
            if ($record) {
                return $record;
            }
        }

        if ($agentId && $clientRequestId) {
            return MockInsurerPolicy::where('agent_id', $agentId)
                ->where('client_request_id', $clientRequestId)
                ->first();
        }

        return null;
    }

    /**
     * Complete an existing pending mock policy (for testing delayed callbacks).
     */
    public function markAsConfirmed(string $providerReference): ?MockInsurerPolicy
    {
        $policy = MockInsurerPolicy::where('provider_reference', $providerReference)->first();
        if ($policy) {
            $policy->status = 'ISSUED';
            $policy->save();
        }
        return $policy;
    }
}
