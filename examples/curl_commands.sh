#!/bin/bash
# PolicyFlow API Demonstration & Testing Scripts
# Base URL: http://localhost:8000

BASE_URL="http://127.0.0.1:8000"

echo "=========================================================="
echo "1. Create New Policy Request (Expected: 201 Created, Premium: 225.00)"
echo "=========================================================="
curl -i -X POST "$BASE_URL/api/policy-requests" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{
    "client_request_id": "AGT-2026-0001",
    "agent_id": "AGENT-17",
    "customer_name": "Sample Customer",
    "date_of_birth": "1998-04-10",
    "destination": "INTERNATIONAL",
    "trip_start": "2026-11-10",
    "trip_end": "2026-11-14",
    "plan_code": "PLUS",
    "sum_insured": 300000
  }'

echo -e "\n\n=========================================================="
echo "2. Re-send Same Request (Duplicate Intake Test - Expected: 200 OK with is_duplicate: true)"
echo "=========================================================="
curl -i -X POST "$BASE_URL/api/policy-requests" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{
    "client_request_id": "AGT-2026-0001",
    "agent_id": "AGENT-17",
    "customer_name": "Sample Customer",
    "date_of_birth": "1998-04-10",
    "destination": "INTERNATIONAL",
    "trip_start": "2026-11-10",
    "trip_end": "2026-11-14",
    "plan_code": "PLUS",
    "sum_insured": 300000
  }'

echo -e "\n\n=========================================================="
echo "3. Query Policy Status & Audit Trail"
echo "=========================================================="
curl -i -X GET "$BASE_URL/api/policy-requests/1" \
  -H "Accept: application/json"

echo -e "\n\n=========================================================="
echo "4. Submit Policy for Issuance (SUCCESS Scenario)"
echo "=========================================================="
curl -i -X POST "$BASE_URL/api/policy-requests/1/submit" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{"mock_scenario": "SUCCESS"}'

echo -e "\n\n=========================================================="
echo "5. Timeout Scenario & Safe Reconciliation"
echo "   Create a new policy and simulate TIMEOUT_AFTER_ISSUE"
echo "=========================================================="
CREATE_RES=$(curl -s -X POST "$BASE_URL/api/policy-requests" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{
    "client_request_id": "AGT-TIMEOUT-TEST",
    "agent_id": "AGENT-17",
    "customer_name": "Timeout User",
    "date_of_birth": "1990-01-01",
    "destination": "DOMESTIC",
    "trip_start": "2026-12-01",
    "trip_end": "2026-12-05",
    "plan_code": "BASIC",
    "sum_insured": 100000
  }')

echo "Created Policy: $CREATE_RES"
TIMEOUT_ID=$(echo "$CREATE_RES" | grep -o '"id":[0-9]*' | head -1 | cut -d: -f2)

echo -e "\nSubmitting with TIMEOUT_AFTER_ISSUE..."
curl -i -X POST "$BASE_URL/api/policy-requests/$TIMEOUT_ID/submit" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{"mock_scenario": "TIMEOUT_AFTER_ISSUE"}'

echo -e "\nReconciling policy after timeout (queries insurer provider status)..."
curl -i -X POST "$BASE_URL/api/policy-requests/$TIMEOUT_ID/reconcile" \
  -H "Accept: application/json"

echo -e "\n\n=========================================================="
echo "6. Webhook Idempotency: Duplicate Callback Delivery"
echo "=========================================================="
EVENT_PAYLOAD='{
  "event_id": "EVT-TEST-IDEMP-001",
  "event_type": "POLICY_ISSUED",
  "client_request_id": "AGT-2026-0001",
  "agent_id": "AGENT-17",
  "policy_number": "POL-CALLBACK-999"
}'

echo "Delivery 1 of webhook callback..."
curl -i -X POST "$BASE_URL/api/mock-insurer/callback" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d "$EVENT_PAYLOAD"

echo -e "\nDelivery 2 of SAME webhook callback (Expected: DUPLICATE_IGNORED)..."
curl -i -X POST "$BASE_URL/api/mock-insurer/callback" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d "$EVENT_PAYLOAD"
