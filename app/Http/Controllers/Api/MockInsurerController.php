<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MockInsurerPolicy;
use App\Models\PolicyRequest;
use App\Services\Issuance\IssuanceService;
use App\Services\MockInsurer\MockInsurerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class MockInsurerController extends Controller
{
    public function __construct(
        protected MockInsurerService $mockInsurer,
        protected IssuanceService $issuanceService
    ) {}

    /**
     * Simulated external insurer issuance endpoint.
     */
    public function issue(Request $request): JsonResponse
    {
        $scenario = $request->header('X-Mock-Scenario') ?: $request->input('mock_scenario', 'SUCCESS');

        try {
            $result = $this->mockInsurer->issuePolicy($request->all(), $scenario);
            return response()->json($result, $result['status'] === 'PENDING' ? 202 : 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'ERROR',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Provider policy status lookup for reconciliation.
     */
    public function show(string $providerReference): JsonResponse
    {
        $policy = $this->mockInsurer->queryPolicyStatus($providerReference);

        if (!$policy) {
            return response()->json([
                'success' => false,
                'status' => 'NOT_FOUND',
                'message' => "No policy record found for provider reference [{$providerReference}].",
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $policy,
        ]);
    }

    /**
     * Receive simulated provider callback event.
     * Enforces idempotency via event_id.
     */
    public function handleCallback(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'event_id' => ['required', 'string'],
            'event_type' => ['nullable', 'string'],
            'provider_reference' => ['nullable', 'string'],
            'client_request_id' => ['nullable', 'string'],
            'agent_id' => ['nullable', 'string'],
            'policy_number' => ['nullable', 'string'],
            'reason' => ['nullable', 'string'],
        ]);

        $result = $this->issuanceService->handleCallback($payload);

        return response()->json($result, 200);
    }

    /**
     * Convenience helper to trigger an asynchronous insurer callback (supports duplicate simulation).
     */
    public function triggerCallback(Request $request): JsonResponse
    {
        $providerRef = $request->input('provider_reference');
        $policy = PolicyRequest::where('provider_reference', $providerRef)->first();

        if (!$policy) {
            return response()->json([
                'success' => false,
                'message' => "No policy found with provider reference [{$providerRef}].",
            ], 404);
        }

        $eventId = $request->input('event_id') ?: ('EVT-' . strtoupper(Str::random(12)));
        $policyNumber = $policy->policy_number ?: ('POL-' . date('Ymd') . '-' . strtoupper(Str::random(6)));

        // Update mock insurer record as confirmed
        $this->mockInsurer->markAsConfirmed($providerRef);

        $payload = [
            'event_id' => $eventId,
            'event_type' => $request->input('event_type', 'POLICY_ISSUED'),
            'provider_reference' => $providerRef,
            'policy_number' => $policyNumber,
            'client_request_id' => $policy->client_request_id,
            'agent_id' => $policy->agent_id,
            'timestamp' => now()->toIso8601String(),
        ];

        // Process callback through idempotent issuance handler
        $result = $this->issuanceService->handleCallback($payload);

        return response()->json([
            'success' => true,
            'message' => 'Simulated callback executed.',
            'callback_payload' => $payload,
            'result' => $result,
        ]);
    }
}
