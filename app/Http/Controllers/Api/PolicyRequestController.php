<?php

namespace App\Http\Controllers\Api;

use App\Enums\PolicyStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\CreatePolicyRequest;
use App\Models\PolicyRequest;
use App\Models\PolicyStateHistory;
use App\Services\Issuance\IssuanceService;
use App\Services\Pricing\PremiumCalculator;
use App\Services\StateMachine\PolicyStateMachine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PolicyRequestController extends Controller
{
    public function __construct(
        protected PremiumCalculator $calculator,
        protected PolicyStateMachine $stateMachine,
        protected IssuanceService $issuanceService
    ) {}

    /**
     * List policy requests with status filter and pagination.
     */
    public function index(Request $request): JsonResponse
    {
        $query = PolicyRequest::query()->orderBy('id', 'desc');

        if ($request->filled('status')) {
            $query->where('status', strtoupper($request->query('status')));
        }

        if ($request->filled('agent_id')) {
            $query->where('agent_id', $request->query('agent_id'));
        }

        if ($request->filled('search')) {
            $search = $request->query('search');
            $query->where(function ($q) use ($search) {
                $q->where('client_request_id', 'like', "%{$search}%")
                  ->orWhere('policy_number', 'like', "%{$search}%")
                  ->orWhere('customer_name', 'like', "%{$search}%");
            });
        }

        $perPage = (int) $request->query('per_page', 15);
        $policies = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $policies->items(),
            'pagination' => [
                'current_page' => $policies->currentPage(),
                'last_page' => $policies->lastPage(),
                'per_page' => $policies->perPage(),
                'total' => $policies->total(),
            ],
        ]);
    }

    /**
     * Create or safely return existing request.
     * Enforces idempotency on (agent_id, client_request_id).
     */
    public function store(CreatePolicyRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $policy = null;
        $isExisting = false;

        DB::transaction(function () use ($validated, &$policy, &$isExisting) {
            // Check for existing request with pessimistic lock on the unique pair
            $existing = PolicyRequest::where('agent_id', $validated['agent_id'])
                ->where('client_request_id', $validated['client_request_id'])
                ->lockForUpdate()
                ->first();

            if ($existing) {
                $policy = $existing;
                $isExisting = true;
                return;
            }

            // Calculate deterministic premium snapshot
            $pricing = $this->calculator->calculate(
                planCode: $validated['plan_code'],
                sumInsured: $validated['sum_insured'],
                destination: $validated['destination'],
                dateOfBirth: $validated['date_of_birth'],
                tripStart: $validated['trip_start'],
                tripEnd: $validated['trip_end']
            );

            // Create new record
            $policy = PolicyRequest::create([
                'uuid' => (string) Str::uuid(),
                'client_request_id' => $validated['client_request_id'],
                'agent_id' => $validated['agent_id'],
                'customer_name' => $validated['customer_name'],
                'date_of_birth' => $validated['date_of_birth'],
                'destination' => strtoupper($validated['destination']),
                'trip_start' => $validated['trip_start'],
                'trip_end' => $validated['trip_end'],
                'travel_days' => $pricing['travel_days'],
                'plan_code' => strtoupper($validated['plan_code']),
                'sum_insured' => (float) $validated['sum_insured'],
                'premium_amount' => (float) $pricing['final_premium'],
                'currency' => $pricing['currency'],
                'pricing_breakdown' => $pricing,
                'status' => PolicyStatus::RECEIVED->value,
            ]);

            // Record initial history
            PolicyStateHistory::create([
                'policy_request_id' => $policy->id,
                'from_state' => null,
                'to_state' => PolicyStatus::RECEIVED->value,
                'reason' => 'Policy request received and validated successfully',
                'metadata' => ['pricing' => $pricing],
                'created_at' => now(),
            ]);

            // Immediately transition to VALIDATED
            $this->stateMachine->transition(
                $policy,
                PolicyStatus::VALIDATED,
                'Policy validated and priced; eligible for issuance submission'
            );
        });

        $statusCode = $isExisting ? 200 : 201;

        return response()->json([
            'success' => true,
            'is_duplicate' => $isExisting,
            'message' => $isExisting
                ? 'Existing policy request safely returned (idempotent replay).'
                : 'Policy request created and validated successfully.',
            'data' => $policy->fresh(['stateHistories', 'attempts']),
        ], $statusCode);
    }

    /**
     * Retrieve status, premium, and audit references for a specific request.
     */
    public function show(string|int $id): JsonResponse
    {
        $policy = PolicyRequest::with(['attempts', 'stateHistories', 'providerEvents'])
            ->where('id', $id)
            ->orWhere('uuid', $id)
            ->first();

        if (!$policy) {
            return response()->json([
                'success' => false,
                'message' => "Policy request [{$id}] not found.",
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $policy,
        ]);
    }

    /**
     * Start or safely resume issuance.
     */
    public function submit(string|int $id, Request $request): JsonResponse
    {
        $policy = PolicyRequest::where('id', $id)->orWhere('uuid', $id)->first();
        if (!$policy) {
            return response()->json([
                'success' => false,
                'message' => "Policy request [{$id}] not found.",
            ], 404);
        }

        // Scenario override from header or body for test/demo flexibility
        $scenario = $request->header('X-Mock-Scenario') ?: $request->input('mock_scenario', 'SUCCESS');

        try {
            $updated = $this->issuanceService->submit($policy, $scenario);

            return response()->json([
                'success' => true,
                'message' => "Issuance workflow executed with status [{$updated->status}].",
                'data' => $updated->load(['attempts', 'stateHistories']),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Reconcile status of ambiguous policy request with mock insurer.
     */
    public function reconcile(string|int $id): JsonResponse
    {
        $policy = PolicyRequest::where('id', $id)->orWhere('uuid', $id)->first();
        if (!$policy) {
            return response()->json([
                'success' => false,
                'message' => "Policy request [{$id}] not found.",
            ], 404);
        }

        $reconciled = $this->issuanceService->reconcile($policy);

        return response()->json([
            'success' => true,
            'message' => "Reconciliation completed. Current status: [{$reconciled->status}].",
            'data' => $reconciled->load(['attempts', 'stateHistories']),
        ]);
    }
}
