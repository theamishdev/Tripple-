<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Policy Requests
        Schema::create('policy_requests', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('client_request_id');
            $table->string('agent_id');
            $table->string('customer_name');
            $table->date('date_of_birth');
            $table->string('destination'); // DOMESTIC, INTERNATIONAL
            $table->date('trip_start');
            $table->date('trip_end');
            $table->unsignedInteger('travel_days');
            $table->string('plan_code'); // BASIC, PLUS, PREMIUM
            $table->decimal('sum_insured', 12, 2);
            $table->decimal('premium_amount', 10, 2);
            $table->string('currency', 3)->default('INR');
            $table->json('pricing_breakdown');
            $table->string('status')->default('RECEIVED')->index();
            $table->string('policy_number')->nullable()->unique();
            $table->string('provider_reference')->nullable()->index();
            $table->unsignedInteger('attempt_count')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->timestamps();

            // Enforce intake idempotency at database level
            $table->unique(['agent_id', 'client_request_id'], 'uq_policy_agent_client_req');
        });

        // 2. Issuance Attempts
        Schema::create('issuance_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('policy_request_id')->constrained('policy_requests')->cascadeOnDelete();
            $table->unsignedInteger('attempt_number');
            $table->string('scenario')->nullable();
            $table->string('status'); // SUCCESS, TEMPORARY_FAILURE, TIMEOUT, PENDING
            $table->json('request_payload')->nullable();
            $table->json('response_payload')->nullable();
            $table->text('error_message')->nullable();
            $table->unsignedInteger('latency_ms')->nullable();
            $table->string('provider_reference')->nullable();
            $table->timestamps();
        });

        // 3. Provider Events (Callback Idempotency)
        Schema::create('provider_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_id')->unique(); // Unique provider event identifier
            $table->string('provider_reference')->nullable()->index();
            $table->foreignId('policy_request_id')->nullable()->constrained('policy_requests')->nullOnDelete();
            $table->string('event_type'); // POLICY_ISSUED, POLICY_REJECTED, etc.
            $table->json('payload');
            $table->string('status')->default('PROCESSED'); // PROCESSED, DUPLICATE_IGNORED, FAILED
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });

        // 4. Policy State History (Audit Trail)
        Schema::create('policy_state_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('policy_request_id')->constrained('policy_requests')->cascadeOnDelete();
            $table->string('from_state')->nullable();
            $table->string('to_state');
            $table->string('reason');
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        // 5. Mock Insurer Registry (Simulated external provider store for stateful lookup & reconciliation)
        Schema::create('mock_insurer_policies', function (Blueprint $table) {
            $table->id();
            $table->string('provider_reference')->unique();
            $table->string('client_request_id');
            $table->string('agent_id');
            $table->string('policy_number')->unique();
            $table->string('status'); // ISSUED, PENDING, REJECTED
            $table->string('plan_code');
            $table->decimal('premium_amount', 10, 2);
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->index(['agent_id', 'client_request_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mock_insurer_policies');
        Schema::dropIfExists('policy_state_histories');
        Schema::dropIfExists('provider_events');
        Schema::dropIfExists('issuance_attempts');
        Schema::dropIfExists('policy_requests');
    }
};
