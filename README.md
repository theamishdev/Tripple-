# POLICYFLOW

### Fault-Tolerant Insurance Issuance & Reconciliation Engine
**Campus Placement | Software Engineering Practical Assessment**

![PolicyFlow Dashboard](./image%20copy.png)

PolicyFlow is an enterprise-grade, fault-tolerant travel insurance intake, issuance, and reconciliation engine built with PHP 8.2+ and Laravel. It receives policy purchase requests from distribution agents, calculates deterministic premiums, submits them to external insurers, and tracks their full lifecycle.

Because external insurers are inherently unreliable (network timeouts, repeated callbacks, delayed underwriting, temporary service blips), PolicyFlow is engineered with strict idempotency guarantees, explicit state machines, and status reconciliation to prevent duplicate issuance and state corruption.

---

## 1. Key Engineering Highlights & Idempotency Strategy

1. **Intake Idempotency**:
   - Unique composite constraint in database: `UNIQUE(agent_id, client_request_id)`.
   - Near-simultaneous or repeated POST requests with the same `(agent_id, client_request_id)` return **HTTP 200 OK** with the existing request snapshot (`is_duplicate: true`) instead of creating duplicate records or throwing 500 errors.
   - Newly created requests return **HTTP 201 Created**.

2. **Deterministic Decimal-Safe Pricing**:
   - Calculated using PHP `bcmath` (`bcmul`) to prevent IEEE 754 floating-point inaccuracies.
   - Strictly validates dates (`trip_end >= trip_start`), inclusive travel days (`trip_end - trip_start + 1`, minimum 1), age tiers on trip start date, destination factors, and coverage limits.
   - The computed premium is saved as an **immutable snapshot** (`premium_amount`, `pricing_breakdown`) that is never lost or recalculated on retries.

3. **Strict State Machine & Audit Trail**:
   - Explicit state transitions: `RECEIVED` &rarr; `VALIDATED` &rarr; `SUBMITTED` &rarr; `PENDING_CONFIRMATION` / `ISSUED` / `FAILED` / `NEEDS_RECONCILIATION`.
   - **Terminal State Protection**: Once a policy is `ISSUED`, it cannot regress or be modified. Illegal transitions (e.g. `ISSUED` &rarr; `SUBMITTED` or `ISSUED` &rarr; `FAILED`) throw `InvalidStateTransitionException`.
   - Every transition writes an immutable audit record in `policy_state_histories` capturing `from_state`, `to_state`, `reason`, `metadata`, and timestamps.

4. **Ambiguous Timeout Handling & Reconciliation**:
   - When an external call times out (`TIMEOUT_AFTER_ISSUE`), PolicyFlow transitions the request to `NEEDS_RECONCILIATION` rather than blindly retrying.
   - Re-submission is prohibited while in `NEEDS_RECONCILIATION`.
   - The reconciliation service queries the external insurer's status endpoint (`/api/policy-requests/{id}/reconcile`) to verify whether the policy was actually created externally before taking any further action.

5. **Callback Idempotency**:
   - Incoming provider webhooks (`POST /api/mock-insurer/callback`) record every event in `provider_events` with unique `event_id`.
   - Duplicate callback deliveries (sent 2–3 times) are recognized and return `DUPLICATE_IGNORED` with HTTP 200 without duplicate state changes.

---

## 2. Implemented Reliability Scenarios

| Scenario | Simulated External Behavior | Engine Handling | Automated Test |
| :--- | :--- | :--- | :--- |
| **Duplicate Intake** | Agent re-submits identical `client_request_id` + `agent_id` | Database unique constraint + transactional lock detects replay; returns HTTP 200 with existing policy | `PolicyIntakeApiTest::test_repeated_submission_is_idempotent_and_returns_200` |
| **Timeout After Issue** | Insurer issues policy internally but HTTP call times out | Transitions to `NEEDS_RECONCILIATION`. Re-issue is blocked. Reconcile endpoint verifies provider state and completes issuance without duplicate policy number | `PolicyIssuanceAndReliabilityTest::test_timeout_after_issue_transitions_to_reconciliation_and_reconciles_safely` |
| **Duplicate Callback** | Provider sends duplicate webhook events (2–3 times) | Unique `event_id` check in `provider_events`; processes 1st event, marks repeats as `DUPLICATE_IGNORED`; never overwrites `ISSUED` state | `PolicyIssuanceAndReliabilityTest::test_duplicate_callback_is_idempotent_and_does_not_corrupt_state` |
| **Temporary Failure & Retry** | External insurer returns 503 / temporary failure | Attempt logged in `issuance_attempts`; request marked `FAILED` (retriable); subsequent retry succeeds while preserving original accepted premium | `PolicyIssuanceAndReliabilityTest::test_temporary_failure_and_safe_retry_preserves_original_premium` |

---

## 3. Architecture Overview

```
 [ Agent / Client ] 
        │
        ▼ (POST /api/policy-requests)
 ┌─────────────────────────────────────────────────────────────┐
 │ Policy Intake Controller                                    │
 │  - Validates input (dates, plans, coverage)                │
 │  - Checks (agent_id, client_request_id) uniqueness         │
 └──────────────────────────────┬──────────────────────────────┘
                                │
                                ▼
 ┌─────────────────────────────────────────────────────────────┐
 │ PremiumCalculator (Decimal-safe bcmath)                     │
 │  - Inclusive days, age multiplier, destination, coverage   │
 │  - Saves immutable pricing breakdown snapshot               │
 └──────────────────────────────┬──────────────────────────────┘
                                │
                                ▼
 ┌─────────────────────────────────────────────────────────────┐
 │ PolicyStateMachine & IssuanceService                        │
 │  - Pessimistic Locking (lockForUpdate)                      │
 │  - Audit trail logging (policy_state_histories)             │
 └──────┬───────────────────────┬──────────────────────────────┘
        │                       │
 (Success / Timeout)      (Callback Webhook)
        │                       │
        ▼                       ▼
 ┌──────────────────────┐ ┌────────────────────────────────────┐
 │ Mock Insurer Service │ │ ProviderEvent Idempotency Handler  │
 │  - SUCCESS           │ │  - Unique event_id check           │
 │  - TIMEOUT           │ │  - Transitions PENDING -> ISSUED   │
 │  - TEMP_FAILURE      │ │  - Prevents regression on ISSUED   │
 │  - DELAYED_CALLBACK  │ └────────────────────────────────────┘
 └──────────────────────┘
```

---

## 4. Local Setup & Running Instructions

### Prerequisites
- PHP 8.2 or newer (with `bcmath`, `pdo_sqlite`, `curl`, `mbstring` extensions enabled)
- Composer 2.x
- Git

### Installation Steps

1. **Clone the repository**:
   ```bash
   git clone https://github.com/theamishdev/Tripple-.git
   cd Tripple-
   ```

2. **Install PHP dependencies**:
   ```bash
   composer install --no-interaction
   ```

3. **Configure Environment**:
   ```bash
   cp .env.example .env
   php artisan key:generate
   ```

4. **Initialize SQLite Database & Run Migrations**:
   ```bash
   # Touch database file if not present
   touch database/database.sqlite

   # Run migrations
   php artisan migrate
   ```

5. **Start Local Development Server**:
   ```bash
   php artisan serve
   ```
   The application is now running at `http://127.0.0.1:8000`.

---

## 5. Running Automated Tests

All business logic, pricing edge cases, state transitions, and reliability scenarios are thoroughly tested via PHPUnit:

```bash
php artisan test
```

### Test Suite Summary:
- **`Tests\Unit\PremiumCalculatorTest`**: 8 tests covering assessment example (₹225.00), age multipliers, destination rates, plan rates, coverage tiers, and validation rejections.
- **`Tests\Unit\PolicyStateMachineTest`**: 4 tests covering happy path lifecycle, audit trail creation, and rejection of illegal state regressions.
- **`Tests\Feature\PolicyIntakeApiTest`**: 6 tests covering 201 Created intake, 422 validations, 200 idempotent duplicate replay, and query filtering.
- **`Tests\Feature\PolicyIssuanceAndReliabilityTest`**: 5 tests verifying unique policy number generation, duplicate callback idempotency, timeout reconciliation, and retry after 503 failure.

Total: **25 tests, 107 assertions, 100% passing**.

---

## 6. Minimal Operational UI

A minimal, clean single-page dashboard is accessible in your browser at:
`http://127.0.0.1:8000/`

**Features of the Minimal Dashboard**:
- **Quick Fill Buttons**: Instantly populate assessment test data with one click.
- **Intake Form**: Immediate client request validation and premium calculation.
- **Live Status Badges**: Clean color-coded badges for all statuses (`VALIDATED`, `SUBMITTED`, `PENDING_CONFIRMATION`, `ISSUED`, `NEEDS_RECONCILIATION`, `FAILED`).
- **Interactive Scenarios**: Submit policies with simulated scenarios (`SUCCESS`, `TIMEOUT_AFTER_ISSUE`, `TEMPORARY_FAILURE`, `DELAYED_CALLBACK`).
- **One-Click Reconciliation**: Safely reconcile timed-out policies.
- **Simulate Callback**: Test asynchronous webhook callbacks.
- **Audit Trail Inspector**: Modal showing state transition timestamps, reasons, and issuance attempt logs.

---

## 7. REST API Documentation & Examples

### 1. Create Policy Request
`POST /api/policy-requests`

**Request Headers**:
`Content-Type: application/json`, `Accept: application/json`

**Payload**:
```json
{
  "client_request_id": "AGT-2026-0001",
  "agent_id": "AGENT-17",
  "customer_name": "Sample Customer",
  "date_of_birth": "1998-04-10",
  "destination": "INTERNATIONAL",
  "trip_start": "2026-11-10",
  "trip_end": "2026-11-14",
  "plan_code": "PLUS",
  "sum_insured": 300000
}
```

**Response (201 Created)**:
```json
{
  "success": true,
  "is_duplicate": false,
  "message": "Policy request created and validated successfully.",
  "data": {
    "id": 1,
    "uuid": "4db7cae4-b778-4ea1-b21a-4933a388b17a",
    "client_request_id": "AGT-2026-0001",
    "agent_id": "AGENT-17",
    "status": "VALIDATED",
    "travel_days": 5,
    "premium_amount": "225.00",
    "currency": "INR",
    "pricing_breakdown": {
      "final_premium": "225.00",
      "currency": "INR",
      "travel_days": 5,
      "customer_age_at_trip_start": 28,
      "base_daily_rate": "20.00",
      "age_multiplier": "1.00",
      "destination_multiplier": "1.80",
      "coverage_multiplier": "1.25"
    }
  }
}
```
*(Repeated POST with same `agent_id` and `client_request_id` returns HTTP 200 with `is_duplicate: true`)*.

---

### 2. Query Policy Request & Audit Trail
`GET /api/policy-requests/{id}`

Returns policy record along with relationships: `attempts`, `state_histories`, and `provider_events`.

---

### 3. Submit Policy for Issuance
`POST /api/policy-requests/{id}/submit`

**Optional Headers / Body**:
- `X-Mock-Scenario: SUCCESS | TIMEOUT_AFTER_ISSUE | TEMPORARY_FAILURE | DELAYED_CALLBACK`
- Or JSON payload: `{"mock_scenario": "TIMEOUT_AFTER_ISSUE"}`

---

### 4. Reconcile Policy Status
`POST /api/policy-requests/{id}/reconcile`

Safely reconciles requests in `NEEDS_RECONCILIATION` by querying the mock insurer. If confirmed, marks `ISSUED`; if no record found, safely transitions to `FAILED` for retry.

---

### 5. Webhook Callback (Idempotent)
`POST /api/mock-insurer/callback`

**Payload**:
```json
{
  "event_id": "EVT-2026-987654",
  "event_type": "POLICY_ISSUED",
  "provider_reference": "MOCK-REF-ABCD1234EF",
  "policy_number": "POL-20261110-XYZ123"
}
```

Repeated deliveries of the same `event_id` return `{"status": "DUPLICATE_IGNORED"}` with HTTP 200.

---

## 8. Known Limitations & Production Roadmap

- **Queues & Asynchronous Workers**: The current implementation runs the mock insurer call synchronously within the API request lifecycle (with transactional pessimistic locks). In higher-throughput production, issuance can be delegated to background jobs (`Queue::push`) with exponential backoff and dead-letter queues.
- **Distributed Locking**: For multi-node deployments across separate database read/write replicas, a distributed lock (e.g. Redis Redlock) can complement database row-level locking for microsecond-level concurrency.
- **Provider Status Poller Cron**: An automated scheduled task (`Schedule::command('policyflow:reconcile-stale')`) can periodically scan for policies in `NEEDS_RECONCILIATION` or `PENDING_CONFIRMATION` that have exceeded a SLA timeout window and automatically reconcile them.
