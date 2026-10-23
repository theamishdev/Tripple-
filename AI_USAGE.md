# AI Usage & Verification Disclosure

In compliance with Section 10 of the Practical Assessment specification, this document discloses how AI tools were utilized, how their outputs were verified, and how defects/edge-cases were addressed.

---

### 1. Tools Used
- **Antigravity AI (Gemini 3.8 Flash)**: Used as an intelligent pair-programming assistant for rapid boilerplate scaffolding, refactoring, test-case authoring, and documentation generation.
- **Git & Composer**: CLI tooling for package management, environment setup, and version control.

---

### 2. Concrete Ways AI Assisted
1. **Accelerated Test Authoring**:
   AI helped construct comprehensive test suites with precise assertions for the complex pricing formula matrix (testing inclusive days, 4 age tiers, destination factors, and coverage tiers) as well as mocking asynchronous external insurer callback workflows.
2. **Deterministic State Machine Modeling**:
   AI assisted in defining the state transition graph and transition matrix (`RECEIVED`, `VALIDATED`, `SUBMITTED`, `PENDING_CONFIRMATION`, `ISSUED`, `FAILED`, `NEEDS_RECONCILIATION`), ensuring strict immutability for confirmed `ISSUED` policies.
3. **Database Concurrency and Idempotency Architecture**:
   AI helped craft the composite database constraints (`['agent_id', 'client_request_id']`), `ProviderEvent` idempotency table, and pessimistic transaction locking (`lockForUpdate()`) to prevent race conditions during near-simultaneous intake and callbacks.

---

### 3. Incorrect or Incomplete Suggestion Encountered & Corrected
- **Premature Retry on Timeout**:
  An initial AI suggestion proposed catching the network timeout during the external insurer call and immediately invoking a retry attempt.
  - *Why this was problematic*: In insurance distribution, external network timeouts are ambiguous. An insurer may have successfully processed the policy internally even if the network connection dropped before the caller received HTTP 200. Blindly retrying on timeout can cause catastrophic duplicate policy issuance and double-billing.
  - *Correction applied*: The engine was updated to explicitly transition timed-out requests to `NEEDS_RECONCILIATION`. Re-issuance is blocked until an explicit provider status query (`/api/policy-requests/{id}/reconcile` or `/api/mock-insurer/policies/{ref}`) checks the remote provider registry to verify whether the policy actually exists.

- **Floating-Point Drift in Premium Calculations**:
  Standard PHP floating-point multiplication (`*`) can introduce binary rounding artifacts on decimal currencies.
  - *Correction applied*: Enforced PHP `bcmath` (`bcmul`) with high intermediary precision (4 decimal places) and rounded final results strictly with `number_format($raw, 2, '.', '')`.

---

### 4. Verification and Testing Methodology
Every code change was verified through automated test suites and live API execution:
1. **Unit Testing**:
   - `PremiumCalculatorTest`: Validated all pricing rules, inclusive date math, unsupported plans, and verified the assessment document's example (`20 * 5 * 1.00 * 1.80 * 1.25 = ₹225.00`).
   - `PolicyStateMachineTest`: Validated valid state lifecycles and verified that illegal transitions (e.g. `ISSUED -> SUBMITTED` or `ISSUED -> FAILED`) throw `InvalidStateTransitionException`.
2. **Feature & Integration Testing**:
   - `PolicyIntakeApiTest`: Validated HTTP status codes (201 for new, 200 for replayed duplicate, 422 for bad dates/unsupported plans).
   - `PolicyIssuanceAndReliabilityTest`: Ran automated end-to-end tests for duplicate callbacks, timeout reconciliation, and retriable temporary failures.
3. **Automated Suite Execution**:
   - All 25 automated tests pass with 107 assertions.
